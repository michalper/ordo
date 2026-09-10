<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RecalculateSegmentAudienceSizes;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Segment\SegmentAudienceSizeRecalculator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class RecalculateSegmentAudienceSizesTest extends TestCase
{
    public function testExecuteDelegatesToRecalculatorAndLogsCount(): void
    {
        $recalculator = $this->createMock(SegmentAudienceSizeRecalculator::class);
        $recalculator->expects(self::once())->method('recalculateAll')->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: recalculated estimated audience size for 3 segment(s).'
        );

        (new RecalculateSegmentAudienceSizes($recalculator, new CronRunLogger($logger)))->execute();
    }
}
