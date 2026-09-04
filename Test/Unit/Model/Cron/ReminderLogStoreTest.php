<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use PHPUnit\Framework\TestCase;

class ReminderLogStoreTest extends TestCase
{
    public function testCountMatchingAppliesEachConditionAndReturnsFetchOneResult(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('ordo_credit_limit_alert_log', 'COUNT(*)')->willReturnSelf();
        $select->expects(self::exactly(2))->method('where')
            ->willReturnCallback(function (string $condition, $value) use ($select) {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    self::assertSame('customer_id = ?', $condition);
                    self::assertSame(5, $value);
                } else {
                    self::assertSame('threshold_percent = ?', $condition);
                    self::assertSame(80, $value);
                }

                return $select;
            });

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())->method('fetchOne')->with($select)->willReturn(3);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new ReminderLogStore($resourceConnection);

        self::assertSame(3, $store->countMatching('ordo_credit_limit_alert_log', [
            'customer_id = ?' => 5,
            'threshold_percent = ?' => 80,
        ]));
    }

    public function testInsertWritesRowToResolvedTableName(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')
            ->with('ordo_offer_reminder_log', ['offer_id' => 9, 'reminder_type' => 'expiring_soon']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new ReminderLogStore($resourceConnection);

        $store->insert('ordo_offer_reminder_log', ['offer_id' => 9, 'reminder_type' => 'expiring_soon']);
    }
}
