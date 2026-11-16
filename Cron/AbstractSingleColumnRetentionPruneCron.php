<?php

declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Shared "delete every row past a retention window on one timestamp column, log how many" body -
 * confirmed byte-for-byte identical in shape across PruneCronRunLog, PruneMessageLog and
 * PruneVisitorEvents before this extraction. Only the table, the timestamp column, where the
 * retention window comes from (a fixed constant for PruneCronRunLog, Helper\Config for the other
 * two), and the noun phrase in the log line differ.
 *
 * Not shared with PrunePendingPopups/PruneNotifications/PruneSurveyPrompts
 * (AbstractGracePeriodPruneCron) - those delete from more than one condition bucket per run
 * (an acted-upon-past-grace bucket plus one or more never-acted-upon-and-expired buckets), which
 * this single-column shape has no room for.
 */
abstract class AbstractSingleColumnRetentionPruneCron
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    abstract protected function tableName(): string;

    abstract protected function timestampColumn(): string;

    abstract protected function retentionDays(): int;

    /**
     * @return string the noun phrase for the log line, e.g. "message log rows" in
     *   "pruned %d message log rows older than %d days".
     */
    abstract protected function rowNounPhrase(): string;

    public function execute(): void
    {
        $retentionDays = $this->retentionDays();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$retentionDays} days"));

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($this->tableName());

        $deleted = $connection->delete($table, [$this->timestampColumn() . ' < ?' => $cutoff]);

        $this->cronRunLogger->logSummary(
            sprintf('pruned %d %s older than %d days', $deleted, $this->rowNounPhrase(), $retentionDays)
        );
    }
}
