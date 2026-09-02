<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Additive stats log for the 5 cron-driven triggers (reorder reminder, offer expiry, credit
 * limit alert, order approval, win-back) — purely for the admin dashboard's sent count/response
 * rate cards. Each trigger's own narrow log table (e.g. ordo_reorder_reminder_log) still exists
 * and still drives cooldown/dedup logic; this table is a separate, cross-trigger record of
 * "who got emailed" and "did they respond" that those tables don't have room for.
 *
 * "Responded" means the customer placed an order after being sent the trigger — see
 * Observer\RecordTriggerOutcome, which sets acted_at/order_id once that happens. First-plausible
 * match semantics: directional stats, not billing-grade attribution.
 */
class TriggerOutcomeLogger
{
    public const TRIGGER_REORDER_REMINDER = 'reorder_reminder';
    public const TRIGGER_OFFER_EXPIRY = 'offer_expiry';
    public const TRIGGER_CREDIT_LIMIT_ALERT = 'credit_limit_alert';
    public const TRIGGER_ORDER_APPROVAL = 'order_approval';
    public const TRIGGER_WIN_BACK = 'win_back';

    public const TRIGGER_TYPES = [
        self::TRIGGER_REORDER_REMINDER,
        self::TRIGGER_OFFER_EXPIRY,
        self::TRIGGER_CREDIT_LIMIT_ALERT,
        self::TRIGGER_ORDER_APPROVAL,
        self::TRIGGER_WIN_BACK,
    ];

    private const DEFAULT_LOOKBACK_DAYS = 30;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function logSent(string $triggerType, int $customerId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('ordo_trigger_outcome_log'), [
            'trigger_type' => $triggerType,
            'customer_id' => $customerId,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Marks the most recent un-acted outcome row(s) for this customer — across any trigger
     * type, within the lookback window — as responded to by the given order. First-plausible
     * match, not exact attribution: good enough for directional response-rate stats.
     */
    public function markActed(int $customerId, int $orderId, int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_trigger_outcome_log');
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
     * Per trigger_type: sent count, responded count, response rate percent — one aggregate
     * query, not one per trigger, so the dashboard doesn't reintroduce the N+1 pattern the
     * campaign trigger stats were already fixed for.
     *
     * @return array<string, array{sent: int, responded: int, response_rate: float}>
     */
    public function getStats(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_trigger_outcome_log');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, [
                    'trigger_type',
                    'sent' => 'COUNT(*)',
                    'responded' => 'SUM(CASE WHEN acted_at IS NOT NULL THEN 1 ELSE 0 END)',
                ])
                ->group('trigger_type')
        );

        $statsByTrigger = [];
        foreach ($rows as $row) {
            $sent = (int) $row['sent'];
            $responded = (int) $row['responded'];

            $statsByTrigger[$row['trigger_type']] = [
                'sent' => $sent,
                'responded' => $responded,
                'response_rate' => $sent > 0 ? round($responded / $sent * 100, 1) : 0.0,
            ];
        }

        foreach (self::TRIGGER_TYPES as $triggerType) {
            if (!isset($statsByTrigger[$triggerType])) {
                $statsByTrigger[$triggerType] = ['sent' => 0, 'responded' => 0, 'response_rate' => 0.0];
            }
        }

        return $statsByTrigger;
    }
}
