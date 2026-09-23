<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\AutoPickCampaignSplitWinner;
use Ordo\Automation\Model\Campaign\SplitWinnerCalculator;
use Ordo\Automation\Model\Cron\CronRunLogger;
use PHPUnit\Framework\TestCase;

class AutoPickCampaignSplitWinnerTest extends TestCase
{
    public function testExecuteLogsHowManySplitActionsWereDecided(): void
    {
        $calculator = $this->createStub(SplitWinnerCalculator::class);
        $calculator->method('decideWinners')->willReturn(3);

        $cronRunLogger = $this->createMock(CronRunLogger::class);
        $cronRunLogger->expects(self::once())->method('logSummary')
            ->with('auto-picked a winner for 3 split action(s)');

        (new AutoPickCampaignSplitWinner($calculator, $cronRunLogger))->execute();
    }

    public function testExecuteLogsZeroWhenNothingWasDecided(): void
    {
        $calculator = $this->createStub(SplitWinnerCalculator::class);
        $calculator->method('decideWinners')->willReturn(0);

        $cronRunLogger = $this->createMock(CronRunLogger::class);
        $cronRunLogger->expects(self::once())->method('logSummary')
            ->with('auto-picked a winner for 0 split action(s)');

        (new AutoPickCampaignSplitWinner($calculator, $cronRunLogger))->execute();
    }
}
