<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\Recommendation\ProductRecommender;
use PHPUnit\Framework\TestCase;

class ProductRecommenderTest extends TestCase
{
    /** @var int[] */
    private array $limitCalls = [];

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnCallback(function ($count) use ($select) {
            $this->limitCalls[] = $count;
            return $select;
        });

        return $select;
    }

    private function makeRecommender(AdapterInterface $connection): ProductRecommender
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1000);

        return new ProductRecommender($resourceConnection, $dateTime);
    }

    public function testCoPurchaseSignalIsUsedWhenAvailable(): void
    {
        $this->limitCalls = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            ['SKU-A'],                     // own SKUs
            [101, 102],                    // other customers who also bought SKU-A
            ['SKU-B', 'SKU-C', 'SKU-D', 'SKU-E'] // co-purchased SKUs, ranked
        );

        $recommender = $this->makeRecommender($connection);

        self::assertSame(['SKU-B', 'SKU-C', 'SKU-D', 'SKU-E'], $recommender->getRecommendedSkus(42, 4));
    }

    public function testBestSellerFallbackFillsGapWhenNoCoPurchaseSignal(): void
    {
        $this->limitCalls = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            ['SKU-A'], // own SKUs
            [],        // no other customers bought SKU-A -> no co-purchase signal
            ['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'] // best sellers
        );

        $recommender = $this->makeRecommender($connection);

        self::assertSame(['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'], $recommender->getRecommendedSkus(42, 4));
    }

    public function testZeroOrderCustomerGetsPureBestSellerFallback(): void
    {
        $this->limitCalls = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            [],        // no orders at all
            ['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'] // best sellers
        );

        $recommender = $this->makeRecommender($connection);

        self::assertSame(['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'], $recommender->getRecommendedSkus(42, 4));
    }

    public function testOtherCustomersScanIsBounded(): void
    {
        $this->limitCalls = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            ['SKU-A'],
            [101, 102],
            ['SKU-B'],
            ['SKU-X', 'SKU-Y', 'SKU-Z']
        );

        $recommender = $this->makeRecommender($connection);
        $recommender->getRecommendedSkus(42, 4);

        self::assertContains(500, $this->limitCalls);
    }

    public function testReturnsEmptyArrayWhenLimitIsZeroOrLess(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $recommender = $this->makeRecommender($connection);

        self::assertSame([], $recommender->getRecommendedSkus(42, 0));
    }

    public function testBestSellerFallbackIsCachedWithinTheTtlWindow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            [],                                     // customer #1: no orders at all
            ['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'],    // best sellers computed once
            []                                       // customer #2: no orders at all
        );
        $connection->expects(self::exactly(3))->method('fetchCol');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1000);

        $recommender = new ProductRecommender($resourceConnection, $dateTime);

        self::assertSame(['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'], $recommender->getRecommendedSkus(42, 4));
        // Second customer within the TTL window reuses the cached best-seller ranking — only the
        // "own SKUs" lookup hits the DB again, not the best-sellers aggregate.
        self::assertSame(['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'], $recommender->getRecommendedSkus(43, 4));
    }

    public function testBestSellerFallbackRecomputesAfterTheTtlExpires(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            [],                                     // customer #1: no orders at all
            ['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'],    // best sellers computed once
            [],                                     // customer #2: no orders at all
            ['SKU-N']                               // best sellers recomputed after TTL expiry
        );
        $connection->expects(self::exactly(4))->method('fetchCol');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnOnConsecutiveCalls(1000, 1061);

        $recommender = new ProductRecommender($resourceConnection, $dateTime);

        self::assertSame(['SKU-X', 'SKU-Y', 'SKU-Z', 'SKU-W'], $recommender->getRecommendedSkus(42, 4));
        self::assertSame(['SKU-N'], $recommender->getRecommendedSkus(43, 4));
    }
}
