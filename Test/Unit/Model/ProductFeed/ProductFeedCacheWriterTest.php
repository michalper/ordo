<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;
use Ordo\Automation\Model\ProductFeed\ProductFeedRunLog;
use Ordo\Automation\Model\ProductFeed\ProductFeedRunLogFactory;
use Ordo\Automation\Model\ResourceModel\ProductFeedRunLog as ProductFeedRunLogResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ProductFeedCacheWriterTest extends TestCase
{
    private AdapterInterface&\PHPUnit\Framework\MockObject\MockObject $connection;
    private ProductFeedRunLogFactory $runLogFactory;
    private ProductFeedRunLogResource&\PHPUnit\Framework\MockObject\MockObject $runLogResource;
    private ProductFeedCacheWriter $writer;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->runLogFactory = $this->createStub(ProductFeedRunLogFactory::class);
        $this->runLogFactory->method('create')->willReturn($this->createMock(ProductFeedRunLog::class));
        $this->runLogResource = $this->createMock(ProductFeedRunLogResource::class);

        $this->writer = new ProductFeedCacheWriter($resourceConnection, $this->runLogFactory, $this->runLogResource);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWriteSuccessUpsertsFeedRowAndAppendsRunLog(): void
    {
        $this->connection->expects(self::once())->method('query')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE'), ['google_merchant', 1, '<rss></rss>', 3]);
        $this->runLogResource->expects(self::once())->method('save');

        $this->writer->writeSuccess('google_merchant', 1, '<rss></rss>', 3);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWriteSuccessUpsertsForADifferentFeedCode(): void
    {
        $this->connection->expects(self::once())->method('query')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE'), ['meta_catalog', 1, 'id,title', 3]);
        $this->runLogResource->expects(self::once())->method('save');

        $this->writer->writeSuccess('meta_catalog', 1, 'id,title', 3);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWriteErrorUpsertsErrorRowAndAppendsRunLog(): void
    {
        $this->connection->expects(self::once())->method('query')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE'), ['google_merchant', 1, 'boom']);
        $this->runLogResource->expects(self::once())->method('save');

        $this->writer->writeError('google_merchant', 1, 'boom');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWriteErrorTruncatesLongMessages(): void
    {
        $longMessage = str_repeat('x', 300);

        $this->connection->expects(self::once())->method('query')
            ->with(self::anything(), self::callback(fn (array $params) => strlen($params[2]) === 255));

        $this->writer->writeError('google_merchant', 1, $longMessage);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRunLogPersistenceFailureIsSwallowed(): void
    {
        $this->connection->method('query');
        $this->runLogResource->method('save')->willThrowException(new \RuntimeException('db down'));

        // Must not throw - a DB hiccup persisting the history row can't crash the caller.
        $this->writer->writeSuccess('google_merchant', 1, '<rss></rss>', 3);

        self::assertTrue(true);
    }
}
