<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\DispatchScheduledCampaignTriggers;
use Ordo\Automation\Model\Campaign\ScheduledTriggerScanner;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class DispatchScheduledCampaignTriggersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteLogsWhenTriggersFired(): void
    {
        $scanner = $this->createMock(ScheduledTriggerScanner::class);
        $scanner->expects(self::once())->method('scan')->willReturn(2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: fired 2 scheduled campaign trigger(s).'
        );

        (new DispatchScheduledCampaignTriggers($scanner, $this->makeCronRunLogger($logger)))->execute();
    }

    public function testExecuteLogsNothingWhenNoTriggersFired(): void
    {
        $scanner = $this->createMock(ScheduledTriggerScanner::class);
        $scanner->expects(self::once())->method('scan')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        (new DispatchScheduledCampaignTriggers($scanner, $this->makeCronRunLogger($logger)))->execute();
    }
}
