<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Deletes raw visitor events past the configured retention window (default 7 days). This is
 * the enforcement mechanism for the design decision documented on ordo_visitor_event and in
 * README → Phase 5: raw behavioral events are not meant to accumulate indefinitely the way
 * ordo_campaign/ordo_customer_tag do — only the tags VisitorAggregator derives from them are
 * long-lived.
 */
class PruneVisitorEvents
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $retentionDays = $this->config->getTrackingRetentionDays();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_visitor_event');

        $deleted = $connection->delete($table, ['created_at < ?' => $cutoff]);

        $this->logger->info(sprintf('Ordo_Automation: pruned %d visitor events older than %d days.', $deleted, $retentionDays));
    }
}
