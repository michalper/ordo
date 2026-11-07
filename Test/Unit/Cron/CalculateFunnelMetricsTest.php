<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Cron\CalculateFunnelMetrics;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CalculateFunnelMetricsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeCron(AdapterInterface $connection, LoggerInterface $logger): CalculateFunnelMetrics
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return new CalculateFunnelMetrics($resourceConnection, $this->makeCronRunLogger($logger));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCountsEachStageForTheSettledDateAndUpserts(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        // Three countDistinctCustomers() calls (product_view, cart_add, checkout_start), in that
        // order - see the cron's own execute() method.
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(50, 20, 12);
        $connection->method('quoteIdentifier')->willReturnArgument(0);

        $insertArgs = null;
        $connection->expects(self::once())->method('query')->willReturnCallback(
            function (string $sql, array $bind) use (&$insertArgs) {
                $insertArgs = $bind;
                return null;
            }
        );

        $expectedDate = date('Y-m-d', (int) strtotime('-2 days'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains(
            "recomputed funnel stats for {$expectedDate}: 50 product_view, 20 cart_add, 12 checkout_start"
        ));

        $this->makeCron($connection, $logger)->execute();

        self::assertSame([$expectedDate, 50, 20, 12], $insertArgs);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWritesZeroesWhenNoEventsExistForTheSettledDate(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->expects(self::once())->method('query');

        $logger = $this->createStub(LoggerInterface::class);

        $this->makeCron($connection, $logger)->execute();
    }
}
