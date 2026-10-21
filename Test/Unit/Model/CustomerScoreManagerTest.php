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
        $select->method('join')->willReturnSelf();
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
    public function testCountCustomersWithScoreAtLeastReturnsIntCount(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('7');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame(7, $manager->countCustomersWithScoreAtLeast(100));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCountAllCustomersReturnsIntCount(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('123');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame(123, $manager->countAllCustomers());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAllCustomerIdsReturnsEveryCustomerEntityIdAsInt(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn(['1', '2', '3']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        self::assertSame([1, 2, 3], $manager->getAllCustomerIds());
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

    /**
     * Regression test for a real double-counting bug a code audit found: the old
     * getDemographicScore()/addPoints()/setDemographicScore() sequence read then wrote each table
     * independently with no lock held across the read-modify-write, so two overlapping calls
     * could both read the same stale old value and each apply the same delta. This asserts the
     * atomic replacement locks both rows (SELECT ... FOR UPDATE) inside one transaction and
     * writes the correctly-computed new totals.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testApplyDemographicScoreLocksBothRowsAndAppliesTheDelta(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        // First fetchOne is the demographic score (old = 10), second is the running total (old = 50).
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('10', '50');

        $queries = [];
        $connection->method('query')->willReturnCallback(function ($sql, $bind) use (&$queries) {
            $queries[] = [$sql, $bind];
        });
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);
        $result = $manager->applyDemographicScore(42, 15);

        self::assertSame(['delta' => 5, 'scoreBefore' => 50, 'scoreAfter' => 55], $result);
        self::assertCount(2, $queries);
        self::assertSame([42, 15], $queries[0][1]);
        self::assertStringContainsString('ordo_customer_demographic_score', $queries[0][0]);
        self::assertSame([42, 55], $queries[1][1]);
        self::assertStringContainsString('ordo_customer_score', $queries[1][0]);
    }

    /**
     * Regression test for a real bug this atomic replacement itself introduced (caught by a real
     * MFTF run, not by these unit tests): an earlier version of applyDemographicScore()
     * unconditionally INSERTed a placeholder score=0 row into BOTH tables before computing the
     * delta, "just to have something to lock" - so every customer this observer ever evaluated
     * ended up with a permanent ordo_customer_score row, even ones who never matched any scoring
     * rule and previously had no row at all. A customer whose demographic score genuinely doesn't
     * change must get no writes to either table - not a row with score=0.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testApplyDemographicScoreSkipsWritesAndCommitsWhenDeltaIsZero(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('10', '50');
        $connection->expects(self::never())->method('query');
        $connection->expects(self::never())->method('update');
        $connection->expects(self::once())->method('commit');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);
        $result = $manager->applyDemographicScore(42, 10);

        self::assertSame(['delta' => 0, 'scoreBefore' => 50, 'scoreAfter' => 50], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testApplyDemographicScoreCreatesRowsOnACustomersFirstEverNonzeroScore(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        // No existing row in either table - fetchOne() returns false for both.
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(false, false);

        $queries = [];
        $connection->method('query')->willReturnCallback(function ($sql, $bind) use (&$queries) {
            $queries[] = [$sql, $bind];
        });

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);
        $result = $manager->applyDemographicScore(42, 20);

        self::assertSame(['delta' => 20, 'scoreBefore' => 0, 'scoreAfter' => 20], $result);
        self::assertCount(2, $queries);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0][0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[1][0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testApplyDemographicScoreRollsBackAndRethrowsOnFailure(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('db down'));
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerScoreManager($resourceConnection);

        $this->expectException(\RuntimeException::class);
        $manager->applyDemographicScore(42, 15);
    }
}
