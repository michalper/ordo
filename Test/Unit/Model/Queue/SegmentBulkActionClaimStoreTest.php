<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Ordo\Automation\Model\Queue\SegmentBulkActionClaimStore;
use PHPUnit\Framework\TestCase;

class SegmentBulkActionClaimStoreTest extends TestCase
{
    public function testClaimInsertsAndReturnsTrueWhenNotAlreadyClaimed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')
            ->with('ordo_segment_bulk_action_claim', self::callback(static function (array $row): bool {
                return $row['batch_id'] === 'batch-uuid' && $row['customer_id'] === 42;
            }));

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
}
