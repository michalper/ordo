<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\CustomerScoreManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CustomerScoreManagerTest extends TestCase
{
    public function testAddPointsUpsertsWithTheCustomerIdAndPoints(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('query')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE'), [42, 10]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);
        $manager->addPoints(42, 10);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetScoreReturnsStoredScore(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('35');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame(35, $manager->getScore(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetScoreReturnsZeroWhenCustomerHasNoRowYet(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame(0, $manager->getScore(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCustomerIdsWithScoreAtLeastReturnsIntArray(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn(['1', '2']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame([1, 2], $manager->getCustomerIdsWithScoreAtLeast(50));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDemographicScoreReturnsStoredScore(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('12');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame(12, $manager->getDemographicScore(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDemographicScoreReturnsZeroWhenCustomerHasNoRowYet(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame(0, $manager->getDemographicScore(42));
    }

    public function testSetDemographicScoreUpsertsWithTheCustomerIdAndScore(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('query')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE'), [42, 15]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);
        $manager->setDemographicScore(42, 15);
    }
}
