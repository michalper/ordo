<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

/**
 * Deletes ordo_segment_bulk_action_claim rows past their retention window. A claim only needs to
 * outlive the message broker's own redelivery window (seconds to hours, never days) to do its
 * job (see Model\Queue\SegmentBulkActionClaimStore) - it must not accumulate forever the way an
 * audit log would.
 */
class PruneSegmentBulkActionClaims extends AbstractSingleColumnRetentionPruneCron
{
    private const int RETENTION_DAYS = 7;

    protected function tableName(): string
    {
        return 'ordo_segment_bulk_action_claim';
    }

    protected function timestampColumn(): string
    {
        return 'claimed_at';
    }

    protected function retentionDays(): int
    {
        return self::RETENTION_DAYS;
    }

    protected function rowNounPhrase(): string
    {
        return 'segment bulk action claim(s)';
    }
}
