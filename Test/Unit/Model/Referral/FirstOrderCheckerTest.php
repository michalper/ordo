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
}
