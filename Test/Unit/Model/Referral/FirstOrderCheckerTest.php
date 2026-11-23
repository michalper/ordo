<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Referral;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Referral\FirstOrderChecker;
use PHPUnit\Framework\TestCase;

class FirstOrderCheckerTest extends TestCase
{
    private function makeChecker(int $orderCount): FirstOrderChecker
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($orderCount);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return new FirstOrderChecker($resourceConnection);
    }

    public function testIsFirstOrderTrueWhenExactlyOneOrder(): void
    {
        self::assertTrue($this->makeChecker(1)->isFirstOrder(5));
    }

    public function testIsFirstOrderFalseWhenMultipleOrders(): void
    {
        self::assertFalse($this->makeChecker(3)->isFirstOrder(5));
    }

    public function testIsFirstOrderFalseWhenNoOrders(): void
    {
        self::assertFalse($this->makeChecker(0)->isFirstOrder(5));
    }

    /**
     * Regression: canceled orders must not count toward "has a prior order" - a customer whose
     * only real order was canceled and who then places a genuine first order would otherwise be
     * counted as having 2 orders, silently missing the referral-conversion reward this class
     * exists to trigger. Same state != 'canceled' filter every other order-count aggregate in
     * this module already applies (RfmCalculator/ClvCalculator/ChurnRiskCalculator).
     */
    public function testIsFirstOrderExcludesCanceledOrdersFromTheCount(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects(self::exactly(2))->method('where')
            ->willReturnCallback(function (string $condition, $value) use ($select) {
                self::assertContains($condition, ['customer_id = ?', 'state != ?']);
                if ($condition === 'state != ?') {
                    self::assertSame('canceled', $value);
                }
                return $select;
            });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(1);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        self::assertTrue((new FirstOrderChecker($resourceConnection))->isFirstOrder(5));
    }
}
