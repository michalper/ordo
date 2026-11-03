<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Sent/converted stats per campaign (and split-test variant) — the campaign-scoped counterpart
 * of Model\TriggerOutcomeLogger, same shape and same first-plausible-match attribution
 * philosophy (see that class's own docblock). "Converted" means the customer placed an order
 * after being sent this campaign — see Observer\RecordCampaignOutcome, which sets acted_at/
 * order_id once that happens.
 *
 * Additive to ordo_message_log (which already records the send itself via
 * Model\Sms\MessageLogWriter::recordSent()) — this table exists purely to answer "did this
 * campaign convert," the same way ordo_trigger_outcome_log is additive to each cron trigger's
 * own narrow dedup log.
 */
class CampaignOutcomeLogger
{
    private const int DEFAULT_LOOKBACK_DAYS = 30;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function logSent(int $campaignId, ?string $variant, int $customerId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('ordo_campaign_outcome_log'), [
            'campaign_id' => $campaignId,
            'variant' => $variant,
            'customer_id' => $customerId,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Marks the most recent un-acted outcome row(s) for this customer — across any campaign,
     * within the lookback window — as converted by the given order. First-plausible match, not
     * exact attribution: good enough for directional response-rate stats, same as
     * TriggerOutcomeLogger::markActed().
     */
    public function markActed(int $customerId, int $orderId, int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_campaign_outcome_log');
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$lookbackDays} days"));

        $connection->update(
            $table,
            ['acted_at' => date('Y-m-d H:i:s'), 'order_id' => $orderId],
            [
                'customer_id = ?' => $customerId,
                'acted_at IS NULL',
                'sent_at >= ?' => $cutoff,
            ]
        );
    }

    /**
     * Retroactively closes the race Observer\RecordCampaignOutcome can miss: campaign dispatch
     * is async (Model\Queue\CampaignDispatchPublisher, no RabbitMQ guarantee under the default
     * DB queue driver — see AGENTS.md), so a customer's own next real order can save (firing
     * sales_order_save_after) BEFORE this table even has a 'sent' row for that observer to mark
     * — nothing re-checks it afterward in that path, so the conversion would otherwise be
     * silently and permanently lost (see docs/adr/0012-campaign-conversion-catch-up-cron.md,
     * confirmed via a real local reproduction: sent=1/converted=0 forever once the send lands
     * after the order it should have been matched to). Cron\CatchUpCampaignConversions calls
     * this periodically: for every still-un-acted row within the lookback window, attribute the
     * earliest real order the customer placed after sent_at — same first-plausible-match
     * semantics as markActed() itself, just evaluated later instead of at order-save time, and
     * with an explicit sent_at/created_at ordering check since (unlike the real-time observer)
     * this can't assume "any un-acted row was necessarily sent before now".
     *
     * @return int How many rows were caught up (0 if nothing was missed).
     */
    public function catchUpMissedConversions(int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS): int
    {
        $connection = $this->resourceConnection->getConnection();
        $outcomeTable = $this->resourceConnection->getTableName('ordo_campaign_outcome_log');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$lookbackDays} days"));

        /** @var array<int, array{entity_id: string|int, customer_id: string|int, sent_at: string}> $rows */
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($outcomeTable, ['entity_id', 'customer_id', 'sent_at'])
                ->where('acted_at IS NULL')
                ->where('sent_at >= ?', $cutoff)
        );

        $caughtUp = 0;
        foreach ($rows as $row) {
            /** @var array{entity_id: string|int}|false $order */
            $order = $connection->fetchRow(
                $connection->select()
                    ->from($orderTable, ['entity_id'])
                    ->where('customer_id = ?', (int) $row['customer_id'])
                    ->where('created_at > ?', $row['sent_at'])
                    ->order('created_at ASC')
                    ->limit(1)
            );

            if (!$order) {
                continue;
            }

            $connection->update(
                $outcomeTable,
                ['acted_at' => date('Y-m-d H:i:s'), 'order_id' => (int) $order['entity_id']],
                [
                    'entity_id = ?' => (int) $row['entity_id'],
                    'acted_at IS NULL',
                ]
            );
            $caughtUp++;
        }

        return $caughtUp;
    }

    /**
     * Sent/converted/conversion_rate/revenue, grouped by variant (empty-string key '' used for
     * the null/no-split variant, since array keys can't be null) — null $campaignId aggregates
     * across every campaign instead, one row per campaign_id+variant pair.
     *
     * @return array<int, array{
     *     campaign_id: int,
     *     variant: string|null,
     *     sent: int,
     *     converted: int,
     *     conversion_rate: float,
     *     revenue: float
     * }>
     */
    public function getStats(?int $campaignId = null): array
    {
        $connection = $this->resourceConnection->getConnection();
        $outcomeTable = $this->resourceConnection->getTableName('ordo_campaign_outcome_log');
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $select = $connection->select()
            ->from(['o' => $outcomeTable], [
                'campaign_id',
                'variant',
                'sent' => 'COUNT(*)',
                'converted' => 'SUM(CASE WHEN o.acted_at IS NOT NULL THEN 1 ELSE 0 END)',
                'revenue' => 'SUM(so.grand_total)',
            ])
            ->joinLeft(['so' => $orderTable], 'so.entity_id = o.order_id', [])
            ->group(['campaign_id', 'variant']);

        if ($campaignId !== null) {
            $select->where('o.campaign_id = ?', $campaignId);
        }

        /**
         * @var array<int, array{
         *     campaign_id: string|int,
         *     variant: string|null,
         *     sent: string|int,
         *     converted: string|int,
         *     revenue: string|null
         * }> $rows
         */
        $rows = $connection->fetchAll($select);

        $stats = [];
        foreach ($rows as $row) {
            $sent = (int) $row['sent'];
            $converted = (int) $row['converted'];

            $stats[] = [
                'campaign_id' => (int) $row['campaign_id'],
                'variant' => $row['variant'],
                'sent' => $sent,
                'converted' => $converted,
                'conversion_rate' => $sent > 0 ? round($converted / $sent * 100, 1) : 0.0,
                'revenue' => $row['revenue'] !== null ? (float) $row['revenue'] : 0.0,
            ];
        }

        return $stats;
    }
}
