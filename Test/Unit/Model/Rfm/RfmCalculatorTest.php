<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Rfm;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use PHPUnit\Framework\TestCase;

class RfmCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeCalculator(AdapterInterface $connection, int $now = 1700000000): RfmCalculator
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        return new RfmCalculator($resourceConnection, $dateTime);
    }

    public function testGetRecencyDaysComputesDaysSinceLastOrder(): void
    {
        $now = 1700000000;
        $tenDaysAgo = date('Y-m-d H:i:s', $now - 10 * 86400);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn($tenDaysAgo);

        $calculator = $this->makeCalculator($connection, $now);

        self::assertSame(10, $calculator->getRecencyDays(42));
    }

    public function testGetRecencyDaysReturnsNullWhenCustomerHasNoOrders(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(false);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getRecencyDays(42));
    }

    public function testGetFrequencyReturnsOrderCount(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn('5');

        $calculator = $this->makeCalculator($connection);

        self::assertSame(5, $calculator->getFrequency(42));
    }

    public function testGetMonetaryTotalReturnsSumOfGrandTotal(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn('249.90');

        $calculator = $this->makeCalculator($connection);

        self::assertSame(249.90, $calculator->getMonetaryTotal(42));
    }

    public function testGetMonetaryTotalReturnsZeroWhenNoOrders(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(false);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(0.0, $calculator->getMonetaryTotal(42));
    }
}
