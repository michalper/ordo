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

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    public function testClaimAcquiresLockChecksCountAndInsertsWhenNotAlreadyClaimed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : 0
        );
        $connection->expects(self::once())->method('insert')
            ->with('ordo_offer_reminder_log', ['offer_id' => 9]);
        $connection->expects(self::once())->method('query')
            ->with(self::stringContains('RELEASE_LOCK'), self::anything());

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new ReminderLogStore($resourceConnection);

        self::assertTrue($store->claim('ordo_offer_reminder_log', ['offer_id = ?' => 9], ['offer_id' => 9]));
    }

    public function testClaimRefusesAndDoesNotInsertWhenAlreadyMatched(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : 1
        );
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new ReminderLogStore($resourceConnection);

        self::assertFalse($store->claim('ordo_offer_reminder_log', ['offer_id = ?' => 9], ['offer_id' => 9]));
    }

    /**
     * Regression: a concurrent cron run holding the same named lock must make this caller fail
     * closed (never send) rather than proceed without the lock's protection.
     */
    public function testClaimFailsClosedWhenTheLockCannotBeAcquired(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 0 : 0
        );
        $connection->expects(self::never())->method('insert');
        $connection->expects(self::never())->method('query');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new ReminderLogStore($resourceConnection);

        self::assertFalse($store->claim('ordo_offer_reminder_log', ['offer_id = ?' => 9], ['offer_id' => 9]));
    }
}
