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

    /**
     * $triggerOrderId, when given, is the real order whose own dispatch produced this send (the
     * order_placed/referral_converted trigger's own context carries it) — closes the race
     * Observer\RecordCampaignOutcome can otherwise miss: campaign dispatch is async
     * (Model\Queue\CampaignDispatchPublisher, no RabbitMQ guarantee under the default DB queue
     * driver — see AGENTS.md), so by the time THIS send actually lands, the customer's own next
     * real order can already have saved (and fired sales_order_save_after) BEFORE this row even
     * existed for that observer to mark — nothing re-checks an un-acted row afterward in that
     * path, so a genuine conversion would otherwise be silently and permanently lost (confirmed
     * via a real local reproduction: sent=1/converted=0 forever once the send lands after the
     * order it should have been matched to; see docs/adr/0012-campaign-conversion-write-time-
     * catch-up.md). Checking right here, at write time, closes the gap at zero lag and zero
     * steady-state cost — no polling cron needed, since this is the one and only place a 'sent'
     * row is ever created (see that ADR for the earlier, rejected polling-cron approach and why).
     *
     * Matches an order with entity_id STRICTLY GREATER than $triggerOrderId, not just "any other
     * order" — Magento's own auto-increment order ids mean "greater" means "placed later", which
     * is what "conversion" actually requires. A plain exclusion (entity_id != $triggerOrderId)
     * would wrongly match an OLDER order too - e.g. the same campaign's own later re-dispatch for
     * a second order would otherwise immediately (and wrongly) credit itself with the customer's
     * FIRST, already-attributed order, double-crediting a conversion that already happened.
     *
     * $triggerOrderId is also persisted on the inserted row itself (trigger_order_id) - the
     * inverse race from the one this method's catch-up closes: markActed() uses it to refuse
     * crediting a row to the exact order whose own dispatch produced it, in case that dispatch
     * resolves (and inserts this row) before this same order's own sales_order_save_after fires.
     */
    public function logSent(int $campaignId, ?string $variant, int $customerId, ?int $triggerOrderId = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('ordo_campaign_outcome_log'), [
            'campaign_id' => $campaignId,
            'variant' => $variant,
            'customer_id' => $customerId,
            'sent_at' => date('Y-m-d H:i:s'),
            'trigger_order_id' => $triggerOrderId,
        ]);

        if ($triggerOrderId === null) {
            return;
        }

        $orderTable = $this->resourceConnection->getTableName('sales_order');
        /** @var array{entity_id: string|int}|false $order */
        $order = $connection->fetchRow(
            $connection->select()
                ->from($orderTable, ['entity_id'])
                ->where('customer_id = ?', $customerId)
                ->where('entity_id > ?', $triggerOrderId)
                ->order('entity_id ASC')
                ->limit(1)
        );

        if ($order) {
            $this->markActed($customerId, (int) $order['entity_id']);
        }
    }

    /**
     * Marks the most recent un-acted outcome row(s) for this customer — across any campaign,
     * within the lookback window — as converted by the given order. First-plausible match, not
     * exact attribution: good enough for directional response-rate stats, same as
     * TriggerOutcomeLogger::markActed().
     *
     * Excludes any row whose own trigger_order_id is this exact $orderId — a campaign still
     * enabled for order_placed dispatches again on every order, so a customer's own current order
     * can race its own (async, queue-consumed) dispatch: if that dispatch's logSent() lands before
     * this same order's own sales_order_save_after fires Observer\RecordCampaignOutcome, the fresh
     * row it just wrote would otherwise look like any other un-acted row and get credited to the
     * very order that caused it — a logical impossibility (confirmed via a real CI run showing
     * converted=2 instead of 1 in AdminCampaignFunnelReflectsRealSendAndConversionTest). "IS NULL
     * OR <>", not a bare "<>" — MySQL's "NULL <> x" is NULL/false, which would otherwise wrongly
     * exclude every row with no trigger order at all (every trigger type other than
     * order_placed/referral_converted, and every row logged before this column existed).
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
                '(trigger_order_id IS NULL OR trigger_order_id <> ?)' => $orderId,
            ]
        );
    }

    /**
     * Sent/converted/conversion_rate/revenue, grouped by variant (empty-string key '' used for
     * the null/no-split variant, since array keys can't be null) — null $campaignId aggregates
     * across every campaign instead, one row per campaign_id+variant pair.
     *
     * revenue sums base_grand_total, not grand_total — same reasoning as
     * CreditLimitCalculator's own base_total_due choice: a multi-store install can have orders
     * placed under more than one currency, and summing the order-currency grand_total would mix
     * them into one meaningless number.
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
                'revenue' => 'SUM(so.base_grand_total)',
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

    /**
     * Same shape as getStats(), but bounded to sent_at within [$from, $to) and grouped by
     * campaign_id only (not campaign_id+variant) — for ranking campaigns against each other and
     * their own trailing baseline (Cron\DetectAnomalies, see docs/adr/0025), the same "ignore the
     * variant axis" choice Model\ResourceModel\CampaignComparison\Grid\Collection already made for
     * the same reason: this answers a per-campaign question, not a per-variant one. getStats()
     * itself is left untouched (all-time, variant-grouped) rather than retrofitting a nullable
     * date range onto it and every one of its existing call sites.
     *
     * @return array<int, array{
     *     campaign_id: int,
     *     sent: int,
     *     converted: int,
     *     conversion_rate: float,
     *     revenue: float
     * }>
     */
    public function getStatsForWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connection = $this->resourceConnection->getConnection();
        $outcomeTable = $this->resourceConnection->getTableName('ordo_campaign_outcome_log');
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $select = $connection->select()
            ->from(['o' => $outcomeTable], [
                'campaign_id',
                'sent' => 'COUNT(*)',
                'converted' => 'SUM(CASE WHEN o.acted_at IS NOT NULL THEN 1 ELSE 0 END)',
                'revenue' => 'SUM(so.base_grand_total)',
            ])
            ->joinLeft(['so' => $orderTable], 'so.entity_id = o.order_id', [])
            ->where('o.sent_at >= ?', $from->format('Y-m-d H:i:s'))
            ->where('o.sent_at < ?', $to->format('Y-m-d H:i:s'))
            ->group('campaign_id');

        /**
         * @var array<int, array{
         *     campaign_id: string|int,
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

            $stats[(int) $row['campaign_id']] = [
                'campaign_id' => (int) $row['campaign_id'],
                'sent' => $sent,
                'converted' => $converted,
                'conversion_rate' => $sent > 0 ? round($converted / $sent * 100, 1) : 0.0,
                'revenue' => $row['revenue'] !== null ? (float) $row['revenue'] : 0.0,
            ];
        }

        return $stats;
    }
}
