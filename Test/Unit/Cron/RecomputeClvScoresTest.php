<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RecomputeClvScores;
use Ordo\Automation\Model\Clv\ClvCalculator;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class RecomputeClvScoresTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDelegatesToClvCalculatorAndLogs(): void
    {
        $clvCalculator = $this->createMock(ClvCalculator::class);
        $clvCalculator->expects(self::once())->method('recomputeAndStoreScores');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: recomputed CLV projections.'
        );

        (new RecomputeClvScores($clvCalculator, $this->makeCronRunLogger($logger)))->execute();
    }
}
