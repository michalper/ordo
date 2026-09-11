<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Deletes ordo_cron_run_log rows older than the retention window. Unlike every other append-only
 * log/queue table in this module (ordo_notification, ordo_pending_popup, ordo_survey_prompt,
 * ordo_visitor_event), this one had no pruning at all - with ~22 crons logging a row per run
 * (several every 5-15 minutes), it grows unbounded. Same direct-connection-delete shape as
 * PruneNotifications; a fixed 30-day window rather than a configurable one, since the only
 * consumer of this data (the dashboard's "crons failed last 24h" KPI and the Cron Run Log grid)
 * never looks back further than a day or two.
 */
class PruneCronRunLog
{
    private const int RETENTION_DAYS = 30;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_cron_run_log');
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RETENTION_DAYS . ' days'));

        $deleted = $connection->delete($table, ['created_at < ?' => $cutoff]);

        $this->cronRunLogger->logSummary(
            sprintf('pruned %d cron run log row(s) older than %d days', $deleted, self::RETENTION_DAYS)
        );
    }
}
