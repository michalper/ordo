<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Cron\RefreshRssContentBlocks;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\RssFetcher;
use Ordo\Automation\Model\ResourceModel\ContentBlock\Collection;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RefreshRssContentBlocksTest extends TestCase
{
    private CollectionFactory $collectionFactory;
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private RssFetcher $rssFetcher;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);
        $this->rssFetcher = $this->createMock(RssFetcher::class);
        $this->dateTime = $this->createStub(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeCron(): RefreshRssContentBlocks
    {
        return new RefreshRssContentBlocks(
            $this->collectionFactory,
            $this->resourceConnection,
            $this->rssFetcher,
            $this->dateTime,
            $this->logger
        );
    }

    private function makeBlock(int $id): ContentBlock
    {
        $block = $this->createStub(ContentBlock::class);
        $block->method('getEntityId')->willReturn($id);

        return $block;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFetchesStaleBlockAndLogs(): void
    {
        $cron = $this->makeCron();

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn(false);

        $block = $this->makeBlock(1);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$block]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->rssFetcher->expects(self::once())->method('fetch')->with($block);
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: refreshed 1 RSS content block(s).'
        );

        $cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsFreshBlockWithoutCallingFetcher(): void
    {
        $cron = $this->makeCron();

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn('2026-09-03 10:00:00');

        $this->dateTime->method('gmtTimestamp')->willReturn(strtotime('2026-09-03 10:05:00'));

        $block = $this->makeBlock(1);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$block]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->rssFetcher->expects(self::never())->method('fetch');
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: refreshed 0 RSS content block(s).'
        );

        $cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteContinuesProcessingAfterOneBlockThrows(): void
    {
        $cron = $this->makeCron();

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn(false);

        $failingBlock = $this->makeBlock(1);
        $okBlock = $this->makeBlock(2);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$failingBlock, $okBlock]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->rssFetcher->method('fetch')->willReturnCallback(function (ContentBlock $block): void {
            if ($block->getEntityId() === 1) {
                throw new \RuntimeException('network down');
            }
        });

        $this->logger->expects(self::once())->method('error')->with(self::stringContains(
            'RefreshRssContentBlocks failed for content block #1'
        ));
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: refreshed 1 RSS content block(s).'
        );

        $cron->execute();
    }
}
