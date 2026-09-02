<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RecomputeRfmScores;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class RecomputeRfmScoresTest extends TestCase
{
    public function testExecuteDelegatesToRfmCalculatorAndLogs(): void
    {
        $rfmCalculator = $this->createMock(RfmCalculator::class);
        $rfmCalculator->expects(self::once())->method('recomputeAndStoreScores');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: recomputed RFM percentile ranks and quintiles.'
        );

        (new RecomputeRfmScores($rfmCalculator, $logger))->execute();
    }
}
