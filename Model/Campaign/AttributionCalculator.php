<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\MessageLogEvent;

/**
 * Computes ordo_campaign_attribution — a genuinely multi-touch revenue attribution split,
 * additive to (not a replacement for) Model\CampaignOutcomeLogger's existing
 * ordo_campaign_outcome_log, which already answers "did this campaign convert" using a
 * first-plausible-match, single-touch heuristic (see that class's own docblock). This class
 * answers a different, complementary question: "how much of an order's revenue should each
 * campaign that touched this customer before the order be credited with."
 *
 * Attribution model: equal-weight linear attribution across every distinct campaign the customer
 * clicked through (ordo_message_log_event TYPE_CLICKED, joined back to its ordo_message_log row
 * for campaign_id) within the configurable lookback window (Helper\Config::getAttributionWindowDays()),
 * counting only the most recent click per campaign. If a customer clicked through campaigns A and
 * B before placing a 100 order, each is credited with 50.
 *
 * Linear (equal-weight) was chosen over the alternatives deliberately:
 *  - First-touch/last-touch would credit only one campaign, which is exactly what
 *    ordo_campaign_outcome_log already does (single-touch) — this table exists specifically to
 *    stop under-crediting every other campaign that also touched the customer.
 *  - Time-decay (recency-weighted) is defensible but introduces a decay-rate constant that would
 *    need its own tuning/config and is much harder for a merchant to explain in a QBR ("why does
 *    this touch count for 61.8% and not 50%?"). Equal-weight has no such free parameter and is
 *    trivial to explain: "each of the N campaigns you interacted with before buying gets 1/N of
 *    the credit."
 * Each cron pass processes only orders with no attribution rows yet (anti-join, newest first), so
 * re-running never double-counts and an order is attributed once, shortly after placement. An
 * order whose window contained no click-throughs writes no rows and so stays a candidate — a click
 * event logged late for it is still picked up on a later pass while it's inside the window. See
 * ADR 0011 for the trade-off against the previous recompute-everything-every-run behavior.
 */
class AttributionCalculator
{
    /** Caps how many orders a single cron pass processes, so one run can't run unbounded. */
    private const int MAX_ORDERS_PER_RUN = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config
    ) {
    }

    /**
     * Computes attribution for orders placed within the attribution window that have no
     * attribution rows yet, looking back over each order's own customer's campaign click-throughs
     * inside that same window. Returns the number of orders processed (whether or not any
     * campaign touch was found for them).
     */
    public function computeForRecentOrders(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $windowDays = $this->config->getAttributionWindowDays();
        $orderCutoff = date('Y-m-d H:i:s', (int) strtotime("-{$windowDays} days"));

        /**
         * @var array<int, array{
         *     entity_id: string|int,
         *     customer_id: string|int,
         *     grand_total: string|float,
         *     created_at: string
         * }> $orders
         * Anti-join: only orders not yet attributed. The old recompute-everything pass redid the
         * same oldest-1000 orders every hour (one SELECT + DELETE + per-row INSERTs each) and,
         * past the cap, never reached newer orders at all. Newest first so a fresh order is
         * attributed on the next pass even while older zero-touch candidates still fill the cap.
         */
        $orders = $connection->fetchAll(
            $connection->select()
                ->from(
                    ['so' => $this->resourceConnection->getTableName('sales_order')],
                    ['entity_id', 'customer_id', 'grand_total', 'created_at']
                )
                ->joinLeft(
                    ['ca' => $this->resourceConnection->getTableName('ordo_campaign_attribution')],
                    'ca.order_id = so.entity_id',
                    []
                )
                ->where('ca.order_id IS NULL')
                ->where('so.customer_id IS NOT NULL')
                ->where('so.created_at >= ?', $orderCutoff)
                ->order('so.entity_id DESC')
                ->limit(self::MAX_ORDERS_PER_RUN)
        );

        foreach ($orders as $order) {
            $this->computeForOrder(
                (int) $order['entity_id'],
                (int) $order['customer_id'],
                (float) $order['grand_total'],
                (string) $order['created_at'],
                $windowDays
            );
        }

        return count($orders);
    }

    private function computeForOrder(
        int $orderId,
        int $customerId,
        float $grandTotal,
        string $orderCreatedAt,
        int $windowDays
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $attributionTable = $this->resourceConnection->getTableName('ordo_campaign_attribution');
        $touchCutoff = date('Y-m-d H:i:s', strtotime($orderCreatedAt) - $windowDays * 86400);

        /** @var array<int, string|int> $campaignIds */
        $campaignIds = $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from(['ml' => $this->resourceConnection->getTableName('ordo_message_log')], ['campaign_id'])
                ->joinInner(
                    ['mle' => $this->resourceConnection->getTableName('ordo_message_log_event')],
                    'mle.message_log_id = ml.entity_id',
                    []
                )
                ->where('ml.customer_id = ?', $customerId)
                ->where('ml.campaign_id IS NOT NULL')
                ->where('mle.event_type = ?', MessageLogEvent::TYPE_CLICKED)
                ->where('mle.created_at >= ?', $touchCutoff)
                ->where('mle.created_at <= ?', $orderCreatedAt)
        );

        // No delete needed: computeForRecentOrders()'s anti-join guarantees this order has no
        // rows yet, and the unique (order_id, campaign_id) constraint backstops a double run.
        $touchCount = count($campaignIds);
        if ($touchCount === 0) {
            return;
        }

        $revenueShare = round($grandTotal / $touchCount, 4);
        $now = date('Y-m-d H:i:s');

        $rows = [];
        foreach ($campaignIds as $campaignId) {
            $rows[] = [
                'order_id' => $orderId,
                'campaign_id' => (int) $campaignId,
                'customer_id' => $customerId,
                'attributed_revenue' => $revenueShare,
                'touch_count' => $touchCount,
                'computed_at' => $now,
            ];
        }

        $connection->insertMultiple($attributionTable, $rows);
    }

    /**
     * Attributed revenue and touch count — one query for every campaign_id
     * on the current grid page, instead of one query per row (see Ui\Component\Listing\Column\
     * CampaignAttributedRevenue, this method's only caller today).
     *
     * @param int[] $campaignIds
     * @return array<int, array{revenue: float, orders: int}> Keyed by campaign_id — a campaign_id
     *   with no attribution rows yet is simply absent, not present with zeros.
     */
    public function getAttributedRevenueForCampaigns(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        /**
         * @var array<int, array{campaign_id: string|int, revenue: string|null, orders: string|int}> $rows
         */
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('ordo_campaign_attribution'),
                    [
                        'campaign_id',
                        'revenue' => 'SUM(attributed_revenue)',
                        'orders' => 'COUNT(DISTINCT order_id)',
                    ]
                )
                ->where('campaign_id IN (?)', $campaignIds)
                ->group('campaign_id')
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['campaign_id']] = [
                'revenue' => $row['revenue'] !== null ? (float) $row['revenue'] : 0.0,
                'orders' => (int) $row['orders'],
            ];
        }

        return $totals;
    }
}
