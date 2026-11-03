<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\CatchUpCampaignConversions;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatchUpCampaignConversionsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteLogsASummaryWhenConversionsWereCaughtUp(): void
    {
        $campaignOutcomeLogger = $this->createMock(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->expects(self::once())->method('catchUpMissedConversions')->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('3 campaign conversion'));

        (new CatchUpCampaignConversions($campaignOutcomeLogger, $this->makeCronRunLogger($logger)))->execute();
    }

    public function testExecuteLogsNothingWhenNothingWasCaughtUp(): void
    {
        $campaignOutcomeLogger = $this->createMock(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->expects(self::once())->method('catchUpMissedConversions')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        (new CatchUpCampaignConversions($campaignOutcomeLogger, $this->makeCronRunLogger($logger)))->execute();
    }
}
