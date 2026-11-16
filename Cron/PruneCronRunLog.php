<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

/**
 * Deletes ordo_cron_run_log rows older than the retention window. Unlike every other append-only
 * log/queue table in this module (ordo_notification, ordo_pending_popup, ordo_survey_prompt,
 * ordo_visitor_event), this one had no pruning at all - with ~22 crons logging a row per run
 * (several every 5-15 minutes), it grows unbounded. Same direct-connection-delete shape as
 * PruneMessageLog/PruneVisitorEvents (AbstractSingleColumnRetentionPruneCron); a fixed 30-day
 * window rather than a configurable one, since the only consumer of this data (the dashboard's
 * "crons failed last 24h" KPI and the Cron Run Log grid) never looks back further than a day or
 * two.
 */
class PruneCronRunLog extends AbstractSingleColumnRetentionPruneCron
{
    private const int RETENTION_DAYS = 30;

    protected function tableName(): string
    {
        return 'ordo_cron_run_log';
    }

    protected function timestampColumn(): string
    {
        return 'created_at';
    }

    protected function retentionDays(): int
    {
        return self::RETENTION_DAYS;
    }

    protected function rowNounPhrase(): string
    {
        return 'cron run log row(s)';
    }
}
