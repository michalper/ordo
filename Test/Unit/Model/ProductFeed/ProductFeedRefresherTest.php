<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Magento\Catalog\Model\Product;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;
use Ordo\Automation\Model\ProductFeed\CatalogFeedProductFetcher;
use Ordo\Automation\Model\ProductFeed\FeedGeneratorPool;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;
use Ordo\Automation\Model\ProductFeed\ProductFeedRefresher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductFeedRefresherTest extends TestCase
{
    private CatalogFeedProductFetcher&\PHPUnit\Framework\MockObject\MockObject $productFetcher;
    private ProductFeedCacheWriter&\PHPUnit\Framework\MockObject\MockObject $cacheWriter;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->productFetcher = $this->createMock(CatalogFeedProductFetcher::class);
        $this->cacheWriter = $this->createMock(ProductFeedCacheWriter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeStoreManager(array $storeIds): StoreManagerInterface
    {
        $stores = array_map(function (int $storeId) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getName')->willReturn('Store #' . $storeId);
            return $store;
        }, $storeIds);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return $storeManager;
    }

    private function makeGenerator(string $feedCode): FeedGeneratorInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $generator = $this->createMock(FeedGeneratorInterface::class);
        $generator->method('getFeedCode')->willReturn($feedCode);

        return $generator;
    }

    private function makeRefresher(array $generators, StoreManagerInterface $storeManager): ProductFeedRefresher
    {
        return new ProductFeedRefresher(
            new FeedGeneratorPool($generators),
            $this->productFetcher,
            $this->cacheWriter,
            $storeManager,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefreshAllSkipsDisabledGeneratorsWithoutWalkingTheCatalog(): void
    {
        $generator = $this->makeGenerator('google_merchant');
        $generator->method('isEnabled')->willReturn(false);
        $generator->expects(self::never())->method('startFeed');

        $this->productFetcher->expects(self::never())->method('fetchByPage');

        $refresher = $this->makeRefresher(['google_merchant' => $generator], $this->makeStoreManager([1]));
        $result = $refresher->refreshAll();

        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['generated']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefreshAllWalksTheCatalogExactlyOnceAndFeedsEveryActiveGenerator(): void
    {
        $productOne = $this->createStub(Product::class);
        $productTwo = $this->createStub(Product::class);

        $google = $this->makeGenerator('google_merchant');
        $google->method('isEnabled')->willReturn(true);
        $google->expects(self::once())->method('startFeed')->with(1);
        $google->expects(self::exactly(2))->method('addProduct')->with(self::logicalOr($productOne, $productTwo));
        $google->expects(self::once())->method('finishFeed')->willReturn(['content' => '<rss/>', 'productCount' => 2]);

        $meta = $this->makeGenerator('meta_catalog');
        $meta->method('isEnabled')->willReturn(true);
        $meta->expects(self::once())->method('startFeed')->with(1);
        $meta->expects(self::exactly(2))->method('addProduct')->with(self::logicalOr($productOne, $productTwo));
        $meta->expects(self::once())->method('finishFeed')->willReturn(['content' => 'csv', 'productCount' => 2]);

        $this->productFetcher->expects(self::once())->method('fetchByPage')->with(1)
            ->willReturn((function () use ($productOne, $productTwo) {
                yield $productOne;
                yield $productTwo;
            })());

        $this->cacheWriter->expects(self::exactly(2))->method('writeSuccess');
        $this->cacheWriter->expects(self::never())->method('writeError');

        $refresher = $this->makeRefresher(
            ['google_merchant' => $google, 'meta_catalog' => $meta],
            $this->makeStoreManager([1])
        );
        $result = $refresher->refreshAll();

        self::assertSame(2, $result['generated']);
        self::assertSame(4, $result['totalProducts']);
        self::assertSame(0, $result['failed']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefreshAllDropsAFailingGeneratorButKeepsFeedingTheOthers(): void
    {
        $productOne = $this->createStub(Product::class);
        $productTwo = $this->createStub(Product::class);

        $failing = $this->makeGenerator('google_merchant');
        $failing->method('isEnabled')->willReturn(true);
        $failing->method('addProduct')->willThrowException(new \RuntimeException('boom'));

        $surviving = $this->makeGenerator('meta_catalog');
        $surviving->method('isEnabled')->willReturn(true);
        $surviving->expects(self::exactly(2))->method('addProduct');
        $surviving->expects(self::once())->method('finishFeed')->willReturn(['content' => 'csv', 'productCount' => 2]);

        $this->productFetcher->method('fetchByPage')->willReturn((function () use ($productOne, $productTwo) {
            yield $productOne;
            yield $productTwo;
        })());

        $this->cacheWriter->expects(self::once())->method('writeSuccess')->with('meta_catalog', 1, 'csv', 2);
        $this->cacheWriter->expects(self::once())->method('writeError')->with('google_merchant', 1, 'boom');
        $this->logger->expects(self::once())->method('error');

        $refresher = $this->makeRefresher(
            ['google_merchant' => $failing, 'meta_catalog' => $surviving],
            $this->makeStoreManager([1])
        );
        $result = $refresher->refreshAll();

        self::assertSame(1, $result['generated']);
        self::assertSame(1, $result['failed']);
        self::assertCount(1, $result['errors']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefreshAllWalksEachStoreSeparately(): void
    {
        $generator = $this->makeGenerator('google_merchant');
        $generator->method('isEnabled')->willReturn(true);
        $generator->method('finishFeed')->willReturn(['content' => '<rss/>', 'productCount' => 0]);

        $seenStoreIds = [];
        $this->productFetcher->expects(self::exactly(2))->method('fetchByPage')
            ->willReturnCallback(function (int $storeId) use (&$seenStoreIds) {
                $seenStoreIds[] = $storeId;
                return (function () {
                    return;
                    yield;
                })();
            });

        $refresher = $this->makeRefresher(['google_merchant' => $generator], $this->makeStoreManager([1, 2]));
        $refresher->refreshAll();

        self::assertSame([1, 2], $seenStoreIds);
    }
}
