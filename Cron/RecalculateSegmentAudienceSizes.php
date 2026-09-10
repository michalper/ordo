<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Segment\SegmentAudienceSizeRecalculator;

/**
 * Refreshes ordo_segment.estimated_audience_size for every segment, backing the segment grid's
 * "Estimated audience size" column. No enable flag: pure data maintenance with no customer-visible
 * side effect, same reasoning as RecomputeRfmScores - nothing an admin would want to opt out of
 * independently.
 */
class RecalculateSegmentAudienceSizes
{
    public function __construct(
        private readonly SegmentAudienceSizeRecalculator $segmentAudienceSizeRecalculator,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $count = $this->segmentAudienceSizeRecalculator->recalculateAll();

        $this->cronRunLogger->logSummary(sprintf('recalculated estimated audience size for %d segment(s)', $count));
    }
}
