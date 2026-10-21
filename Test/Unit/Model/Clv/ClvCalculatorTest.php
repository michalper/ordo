<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Clv;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Clv\ClvCalculator;
use PHPUnit\Framework\TestCase;

class ClvCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    private function makeCalculator(
        AdapterInterface $connection,
        int $now = 1700000000,
        int $projectionYears = 3,
        int $minTenureMonths = 1
    ): ClvCalculator {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        $config = $this->createStub(Config::class);
        $config->method('getClvProjectionYears')->willReturn($projectionYears);
        $config->method('getClvMinTenureMonths')->willReturn($minTenureMonths);

        return new ClvCalculator($resourceConnection, $dateTime, $config);
    }

    public function testGetProjectedClvMultipliesAovByAnnualizedFrequencyByProjectionWindow(): void
    {
        $now = 1700000000;
        $oneYearAgo = date('Y-m-d H:i:s', $now - 365 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchRow')->willReturn([
            'frequency' => '4',
            'monetary' => '400.00',
            'first_order_at' => $oneYearAgo,
        ]);

        $calculator = $this->makeCalculator($connection, $now, 3);

        // AOV 100 x 4 orders/year x 3-year projection = 1200.
        self::assertEqualsWithDelta(1200.0, $calculator->getProjectedClv(42), 1.0);
    }

    public function testGetProjectedClvReturnsZeroWithNoOrders(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchRow')->willReturn(['frequency' => '0', 'monetary' => null, 'first_order_at' => null]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(0.0, $calculator->getProjectedClv(42));
    }

    public function testComputeClvForAllCustomersScoresEachCustomer(): void
    {
        $now = 1700000000;
        $oneYearAgo = date('Y-m-d H:i:s', $now - 365 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '42',
                'frequency' => '4',
                'monetary' => '400.00',
                'first_order_at' => $oneYearAgo,
            ],
        ]);

        $calculator = $this->makeCalculator($connection, $now, 3);
        $scores = $calculator->computeClvForAllCustomers();

        self::assertEqualsWithDelta(1200.0, $scores[42], 1.0);
    }

    /**
     * The tenure floor (getClvMinTenureMonths) keeps a brand-new customer's first order from
     * annualizing into a near-infinite run rate — a 1-day-old customer with one 100.00 order
     * and a 6-month floor projects as 100 x (1 / 0.5y) x 3y = 600, not thousands.
     */
    public function testProjectionFloorsTenureAtTheConfiguredMinimum(): void
    {
        $now = 1700000000;
        $yesterday = date('Y-m-d H:i:s', $now - 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchRow')->willReturn([
            'frequency' => '1',
            'monetary' => '100.00',
            'first_order_at' => $yesterday,
        ]);

        $calculator = $this->makeCalculator($connection, $now, 3, 6);

        self::assertEqualsWithDelta(600.0, $calculator->getProjectedClv(42), 0.5);
    }

    public function testGetClvScoresReturnsCachedValueWithinTtl(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->expects(self::once())->method('fetchAll')->willReturn([
            ['customer_id' => '42', 'clv_score' => '1234.5000'],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame([42 => 1234.5], $calculator->getClvScores());
        // Second call within the cache TTL must not query the DB again.
        self::assertSame([42 => 1234.5], $calculator->getClvScores());
    }

    public function testGetClvScoresFallsBackToLiveComputeWhenStoredTableEmpty(): void
    {
        $now = 1700000000;
        $oneYearAgo = date('Y-m-d H:i:s', $now - 365 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [],
            [
                [
                    'customer_id' => '42',
                    'frequency' => '4',
                    'monetary' => '400.00',
                    'first_order_at' => $oneYearAgo,
                ],
            ]
        );

        $calculator = $this->makeCalculator($connection, $now, 3);
        $scores = $calculator->getClvScores();

        self::assertEqualsWithDelta(1200.0, $scores[42], 1.0);
    }

    public function testGetClvScoresReadsStoredScoresWhenPopulated(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => '42', 'clv_score' => '1234.5000'],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame([42 => 1234.5], $calculator->getClvScores());
    }

    public function testRecomputeAndStoreScoresDeletesThenInsertsRows(): void
    {
        $now = 1700000000;
        $oneYearAgo = date('Y-m-d H:i:s', $now - 365 * 86400);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '42',
                'frequency' => '4',
                'monetary' => '400.00',
                'first_order_at' => $oneYearAgo,
            ],
        ]);
        $connection->expects(self::once())->method('delete')->with('ordo_customer_clv_score');
        $connection->expects(self::once())->method('insertMultiple')->with(
            'ordo_customer_clv_score',
            self::callback(function (array $rows): bool {
                self::assertCount(1, $rows);
                self::assertSame(42, $rows[0]['customer_id']);
                self::assertEqualsWithDelta(1200.0, $rows[0]['clv_score'], 1.0);

                return true;
            })
        );

        $calculator = $this->makeCalculator($connection, $now, 3);
        $calculator->recomputeAndStoreScores();
    }

    public function testRecomputeAndStoreScoresSkipsInsertWhenNoCustomers(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        $connection->expects(self::once())->method('delete')->with('ordo_customer_clv_score');
        $connection->expects(self::never())->method('insertMultiple');

        $calculator = $this->makeCalculator($connection);
        $calculator->recomputeAndStoreScores();
    }

    public function testResetScoresForCustomersDeletesMatchingRows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('delete')
            ->with('ordo_customer_clv_score', ['customer_id IN (?)' => [42, 43]])
            ->willReturn(2);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(2, $calculator->resetScoresForCustomers([42, 43]));
    }

    public function testResetScoresForCustomersReturnsZeroForEmptyList(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('delete');

        $calculator = $this->makeCalculator($connection);

        self::assertSame(0, $calculator->resetScoresForCustomers([]));
    }

    public function testGetAverageClvScoreReturnsZeroWithNoCustomers(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(0.0, $calculator->getAverageClvScore());
    }

    public function testGetAverageClvScoreAveragesStoredScores(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => '1', 'clv_score' => '100.0'],
            ['customer_id' => '2', 'clv_score' => '300.0'],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(200.0, $calculator->getAverageClvScore());
    }
}
