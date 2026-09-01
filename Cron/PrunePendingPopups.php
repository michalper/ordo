<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Deletes pending popups that are no longer relevant: already delivered (kept briefly for
 * debugging/observability, not indefinitely — nothing ever reads a delivered row again) or
 * expired without ever being polled. Same enforcement role as PruneVisitorEvents, for the same
 * reason: ordo_pending_popup is meant to be a short-lived queue, not an ever-growing log.
 */
class PrunePendingPopups
{
    private const DELIVERED_GRACE_HOURS = 24;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_pending_popup');
        $now = date('Y-m-d H:i:s');
        $deliveredCutoff = date('Y-m-d H:i:s', strtotime('-' . self::DELIVERED_GRACE_HOURS . ' hours'));

        $deletedDelivered = $connection->delete($table, ['delivered_at IS NOT NULL', 'delivered_at < ?' => $deliveredCutoff]);
        $deletedExpired = $connection->delete($table, ['delivered_at IS NULL', 'expires_at IS NOT NULL', 'expires_at < ?' => $now]);

        $this->logger->info(sprintf(
            'Ordo_Automation: pruned %d delivered and %d expired-undelivered pending popups.',
            $deletedDelivered,
            $deletedExpired
        ));
    }
}
