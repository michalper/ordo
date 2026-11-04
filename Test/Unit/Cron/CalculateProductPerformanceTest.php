<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\FlagManager;
use Ordo\Automation\Cron\CalculateProductPerformance;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class CalculateProductPerformanceTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    private function makeCron(
        AdapterInterface $connection,
        LoggerInterface $logger,
        FlagManager $flagManager
    ): CalculateProductPerformance {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return new CalculateProductPerformance($resourceConnection, $flagManager, $this->makeCronRunLogger($logger));
    }

    public function testExecuteDoesNothingWhenThereAreNoNewEvents(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        $connection->expects(self::never())->method('query');

        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(0);
        $flagManager->expects(self::never())->method('saveFlag');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 product view'));

        $this->makeCron($connection, $logger, $flagManager)->execute();
    }

    public function testExecuteUpsertsViewCountsPerSkuAndAdvancesTheWatermark(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 10, 'event_key' => 'SKU-A'],
            ['entity_id' => 11, 'event_key' => 'SKU-B'],
            ['entity_id' => 12, 'event_key' => 'SKU-A'],
        ]);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->expects(self::exactly(2))->method('query');

        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(0);
        $flagManager->expects(self::once())->method('saveFlag')->with(self::anything(), 12);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('3 product view'));

        $this->makeCron($connection, $logger, $flagManager)->execute();
    }

    public function testExecuteReadsNewEventsPastThePersistedWatermark(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);

        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects(self::once())->method('getFlagData')->willReturn(42);

        $logger = $this->createStub(LoggerInterface::class);

        $this->makeCron($connection, $logger, $flagManager)->execute();
    }
}
