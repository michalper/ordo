<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Track;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Track\ProductViewCounter;
use PHPUnit\Framework\TestCase;

class ProductViewCounterTest extends TestCase
{
    public function testCountViewsTodayQueriesProductViewEventsForTheSkuSinceMidnight(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('ordo_visitor_event', ['COUNT(*)'])->willReturnSelf();
        $select->expects(self::exactly(3))->method('where')
            ->willReturnCallback(function (string $condition, $value) use ($select) {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    self::assertSame('event_type = ?', $condition);
                    self::assertSame('product_view', $value);
                } elseif ($call === 2) {
                    self::assertSame('event_key = ?', $condition);
                    self::assertSame('SKU-1', $value);
                } else {
                    self::assertSame('created_at >= ?', $condition);
                    self::assertSame(date('Y-m-d 00:00:00'), $value);
                }

                return $select;
            });

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->with($select)->willReturn('12');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $counter = new ProductViewCounter($resourceConnection);

        self::assertSame(12, $counter->countViewsToday('SKU-1'));
    }
}
