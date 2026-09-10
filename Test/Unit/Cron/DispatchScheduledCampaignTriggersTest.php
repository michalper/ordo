<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\DispatchScheduledCampaignTriggers;
use Ordo\Automation\Model\Campaign\ScheduledTriggerScanner;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class DispatchScheduledCampaignTriggersTest extends TestCase
{
    public function testExecuteLogsWhenTriggersFired(): void
    {
        $scanner = $this->createMock(ScheduledTriggerScanner::class);
        $scanner->expects(self::once())->method('scan')->willReturn(2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: fired 2 scheduled campaign trigger(s).'
        );

        (new DispatchScheduledCampaignTriggers($scanner, new CronRunLogger($logger)))->execute();
    }

    public function testExecuteLogsNothingWhenNoTriggersFired(): void
    {
        $scanner = $this->createMock(ScheduledTriggerScanner::class);
        $scanner->expects(self::once())->method('scan')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        (new DispatchScheduledCampaignTriggers($scanner, new CronRunLogger($logger)))->execute();
    }
}
