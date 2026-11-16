<?php

declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Shared base for PrunePendingPopups, PruneNotifications and PruneSurveyPrompts - each deletes
 * from more than one condition bucket per run (an "acted upon, past a grace period" bucket, plus
 * one or more "never acted upon and expired" buckets), unlike the single-column shape
 * AbstractSingleColumnRetentionPruneCron covers (see docs/adr/0070 and docs/adr/0071).
 *
 * Only the plain "acted upon, past grace" bucket is pulled out here, via deleteWherePastGrace() -
 * it was byte-for-byte identical in PendingPopups' delivered_at delete, Notifications' read_at
 * delete, and SurveyPrompts' responded_at delete. SurveyPrompts' own second grace-based delete
 * (delivered-but-never-answered) needs one extra guard (`responded_at IS NULL`) the plain bucket
 * doesn't have, since a row that was displayed long ago but answered recently must not be
 * deleted - that one stays inline, same as each subclass's own "never acted upon and expired"
 * bucket(s) (PendingPopups/Notifications have one, SurveyPrompts has one), which differ enough
 * between the three (different IS NULL guards) that forcing them into a shared hook would move
 * the real logic behind a parameter list instead of removing duplication.
 */
abstract class AbstractGracePeriodPruneCron
{
    public function __construct(
        protected readonly ResourceConnection $resourceConnection,
        protected readonly CronRunLogger $cronRunLogger
    ) {
    }

    abstract protected function tableName(): string;

    /**
     * Deletes rows where $column is set and older than $graceHours - the "acted upon, nothing
     * will ever read this row again" bucket shared by all three subclasses.
     *
     * @return int number of rows deleted
     */
    protected function deleteWherePastGrace(string $column, int $graceHours): int
    {
        $cutoff = date('Y-m-d H:i:s', (int) strtotime('-' . $graceHours . ' hours'));

        return $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName($this->tableName()),
            [$column . ' IS NOT NULL', $column . ' < ?' => $cutoff]
        );
    }
}
