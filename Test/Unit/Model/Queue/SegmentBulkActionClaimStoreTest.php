<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Queue\SegmentBulkActionClaimStore;
use PHPUnit\Framework\TestCase;

class SegmentBulkActionClaimStoreTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    public function testClaimInsertsAndReturnsTrueWhenNotAlreadyClaimed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')
            ->with('ordo_segment_bulk_action_claim', self::callback(static fn(array $row): bool => $row['batch_id'] === 'batch-uuid' && $row['customer_id'] === 42));

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        self::assertTrue($store->claim('batch-uuid', 42));
    }

    /**
     * The actual race/redelivery this store exists to close: the unique (batch_id, customer_id)
     * constraint rejects a second insert for the same pair, and that failure must be reported as
     * "already claimed" (false), not let the exception propagate and abort the caller's whole
     * batch.
     */
    public function testClaimReturnsFalseWhenTheInsertLosesTheUniqueConstraintRace(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')->willThrowException(new DuplicateException());

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        self::assertFalse($store->claim('batch-uuid', 42));
    }

    public function testUnclaimDeletesTheClaimRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('delete')
            ->with('ordo_segment_bulk_action_claim', ['batch_id = ?' => 'batch-uuid', 'customer_id = ?' => 42]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        $store->unclaim('batch-uuid', 42);
    }

    public function testClaimBatchReturnsEmptyArrayWithoutQueryingAnythingForEmptyInput(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');
        $connection->expects(self::never())->method('insertMultiple');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        self::assertSame([], $store->claimBatch('batch-uuid', []));
    }

    public function testClaimBatchBulkInsertsEveryoneNotAlreadyClaimed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([]);
        $connection->expects(self::once())->method('insertMultiple')
            ->with('ordo_segment_bulk_action_claim', self::callback(static fn(array $rows): bool => count($rows) === 3
                && array_column($rows, 'customer_id') === [1, 2, 3]
                && array_unique(array_column($rows, 'batch_id')) === ['batch-uuid']));

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        self::assertSame([1, 2, 3], $store->claimBatch('batch-uuid', [1, 2, 3]));
    }

    /**
     * Regression for the exact redelivery scenario this store exists for: a customer already
     * claimed (an earlier, now-redelivered attempt at this exact message) must be excluded from
     * the bulk insert entirely, not just reported as unclaimed - and the whole batch must not be
     * skipped just because one customer in it already has a claim row.
     */
    public function testClaimBatchExcludesAlreadyClaimedCustomersFromTheInsert(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([2]);
        $connection->expects(self::once())->method('insertMultiple')
            ->with('ordo_segment_bulk_action_claim', self::callback(static fn(array $rows): bool => array_column($rows, 'customer_id') === [1, 3]));

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        self::assertSame([1, 3], $store->claimBatch('batch-uuid', [1, 2, 3]));
    }

    public function testClaimBatchReturnsEmptyArrayWithoutInsertingWhenEveryoneIsAlreadyClaimed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([1, 2]);
        $connection->expects(self::never())->method('insertMultiple');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        self::assertSame([], $store->claimBatch('batch-uuid', [1, 2]));
    }

    /**
     * A real race within this call's own SELECT-then-insert window (a second, genuinely
     * concurrent worker claiming one of the same customers in between) must not abort every
     * other customer's otherwise-successful claim in the same call - falls back to claim()'s own
     * one-row-at-a-time, unique-constraint-backed claim for just this batch.
     */
    public function testClaimBatchFallsBackToPerRowClaimWhenTheBulkInsertLosesARace(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([]);
        $connection->expects(self::once())->method('insertMultiple')->willThrowException(new DuplicateException());
        $connection->expects(self::exactly(2))->method('insert')
            ->willReturnCallback(static function (string $table, array $row): void {
                if ($row['customer_id'] === 2) {
                    throw new DuplicateException();
                }
            });

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $store = new SegmentBulkActionClaimStore($resourceConnection);
        self::assertSame([1], $store->claimBatch('batch-uuid', [1, 2]));
    }
}
