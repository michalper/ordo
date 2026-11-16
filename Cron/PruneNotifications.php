<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

/**
 * Deletes persistent notifications that are no longer relevant: already read (kept briefly for
 * debugging/observability, same reasoning as PrunePendingPopups keeping delivered rows) or
 * expired without ever being read. Same enforcement role as PrunePendingPopups/
 * PruneVisitorEvents, for the same reason: ordo_notification is meant to be a bounded queue,
 * not an ever-growing log.
 */
class PruneNotifications extends AbstractGracePeriodPruneCron
{
    private const int READ_GRACE_HOURS = 24;

    protected function tableName(): string
    {
        return 'ordo_notification';
    }

    public function execute(): void
    {
        $deletedRead = $this->deleteWherePastGrace('read_at', self::READ_GRACE_HOURS);

        $deletedExpired = $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName($this->tableName()),
            [
                'read_at IS NULL',
                'expires_at IS NOT NULL',
                'expires_at < ?' => date('Y-m-d H:i:s'),
            ]
        );

        $this->cronRunLogger->logSummary(sprintf(
            'pruned %d read and %d expired-unread notifications',
            $deletedRead,
            $deletedExpired
        ));
    }
}
