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
