<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RecomputeRfmScores;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class RecomputeRfmScoresTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDelegatesToRfmCalculatorAndLogs(): void
    {
        $rfmCalculator = $this->createMock(RfmCalculator::class);
        $rfmCalculator->expects(self::once())->method('recomputeAndStoreScores');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: recomputed RFM percentile ranks and quintiles.'
        );

        (new RecomputeRfmScores($rfmCalculator, $this->makeCronRunLogger($logger)))->execute();
    }
}
