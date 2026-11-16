<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Deletes sent messages (ordo_message_log) past the configured retention window (default 180
 * days) - the enforcement mechanism for the gap flagged in docs/adr/0016: unlike
 * ordo_visitor_event (pruned by PruneVisitorEvents), ordo_message_log and its open/click event
 * rows had no pruning at all and accumulated forever. ordo_message_log_event rows are deleted
 * automatically by the table's own ON DELETE CASCADE foreign key to ordo_message_log - no
 * separate delete needed here.
 */
class PruneMessageLog extends AbstractSingleColumnRetentionPruneCron
{
    public function __construct(
        private readonly Config $config,
        ResourceConnection $resourceConnection,
        CronRunLogger $cronRunLogger
    ) {
        parent::__construct($resourceConnection, $cronRunLogger);
    }

    protected function tableName(): string
    {
        return 'ordo_message_log';
    }

    protected function timestampColumn(): string
    {
        return 'sent_at';
    }

    protected function retentionDays(): int
    {
        return $this->config->getMessageLogRetentionDays();
    }

    protected function rowNounPhrase(): string
    {
        return 'message log rows';
    }
}
