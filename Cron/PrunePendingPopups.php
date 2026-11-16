<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

/**
 * Deletes pending popups that are no longer relevant: already delivered (kept briefly for
 * debugging/observability, not indefinitely — nothing ever reads a delivered row again) or
 * expired without ever being polled. Same enforcement role as PruneVisitorEvents, for the same
 * reason: ordo_pending_popup is meant to be a short-lived queue, not an ever-growing log.
 */
class PrunePendingPopups extends AbstractGracePeriodPruneCron
{
    private const int DELIVERED_GRACE_HOURS = 24;

    protected function tableName(): string
    {
        return 'ordo_pending_popup';
    }

    public function execute(): void
    {
        $deletedDelivered = $this->deleteWherePastGrace('delivered_at', self::DELIVERED_GRACE_HOURS);

        $deletedExpired = $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName($this->tableName()),
            [
                'delivered_at IS NULL',
                'expires_at IS NOT NULL',
                'expires_at < ?' => date('Y-m-d H:i:s'),
            ]
        );

        $this->cronRunLogger->logSummary(sprintf(
            'pruned %d delivered and %d expired-undelivered pending popups',
            $deletedDelivered,
            $deletedExpired
        ));
    }
}
