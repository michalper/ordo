<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\ComputeCampaignAttribution;
use Ordo\Automation\Model\Campaign\AttributionCalculator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ComputeCampaignAttributionTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDelegatesToAttributionCalculatorAndLogsOrderCount(): void
    {
        $attributionCalculator = $this->createMock(AttributionCalculator::class);
        $attributionCalculator->expects(self::once())
            ->method('computeForRecentOrders')
            ->willReturn(7);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: recomputed campaign attribution for 7 order(s).'
        );

        (new ComputeCampaignAttribution($attributionCalculator, $this->makeCronRunLogger($logger)))->execute();
    }
}
