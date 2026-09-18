<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\PriceWatch;

use ArrayIterator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\Collection
    as PriceWatchSubscriptionCollection;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\CollectionFactory
    as PriceWatchSubscriptionCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PriceWatchSubscriptionManagerTest extends TestCase
{
    private PriceWatchSubscriptionResource&MockObject $resource;
    private PriceWatchSubscriptionCollectionFactory&MockObject $collectionFactory;
    private ProductRepositoryInterface&MockObject $productRepository;
    private PriceWatchSubscriptionManager $manager;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(PriceWatchSubscriptionResource::class);
        $this->collectionFactory = $this->createMock(PriceWatchSubscriptionCollectionFactory::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->manager = new PriceWatchSubscriptionManager(
            $this->resource,
            $this->collectionFactory,
            $this->productRepository
        );
    }

    private function makeCollection(?PriceWatchSubscription $firstItem = null): PriceWatchSubscriptionCollection
    {
        $collection = $this->createStub(PriceWatchSubscriptionCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addCustomerFilter')->willReturnSelf();
        $collection->method('addVisitorFilter')->willReturnSelf();
        $collection->method('addProductFilter')->willReturnSelf();
        $collection->method('addWatchTypeFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(
            $firstItem ?? $this->createStub(PriceWatchSubscription::class)
        );
        $collection->method('getIterator')->willReturn(new ArrayIterator([]));

        return $collection;
    }

    private function makeProduct(float $price = 100.0, bool $salable = true): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getFinalPrice')->willReturn($price);
        $product->method('isSalable')->willReturn($salable);

        return $product;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterCreatesNewRowCapturingCurrentProductState(): void
    {
        $noRow = $this->createMock(PriceWatchSubscription::class);
        $noRow->method('getId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($noRow));
        $this->productRepository->expects(self::once())->method('getById')->with(5)
            ->willReturn($this->makeProduct(99.0, true));

        $noRow->expects(self::once())->method('setProductId')->with(5);
        $noRow->expects(self::once())->method('setWatchType')->with(PriceWatchSubscription::WATCH_TYPE_PRICE_DROP);
        $noRow->expects(self::once())->method('setLastKnownPrice')->with(99.0);
        $noRow->expects(self::once())->method('setLastKnownInStock')->with(true);
        $noRow->expects(self::once())->method('setNotifiedAt')->with(null);
        $noRow->expects(self::once())->method('setCustomerId')->with(null);
        $noRow->expects(self::once())->method('setVisitorId')->with('visitor-1');
        $noRow->expects(self::once())->method('setCreatedAt');
        $this->resource->expects(self::once())->method('save')->with($noRow);

        $this->manager->register(5, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, null, 'visitor-1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterCapturesGuestEmailOnNewAnonymousRow(): void
    {
        $noRow = $this->createMock(PriceWatchSubscription::class);
        $noRow->method('getId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($noRow));
        $this->productRepository->method('getById')->willReturn($this->makeProduct());

        $noRow->expects(self::once())->method('setGuestEmail')->with('guest@example.com');

        $this->manager->register(
            5,
            PriceWatchSubscription::WATCH_TYPE_PRICE_DROP,
            null,
            'visitor-1',
            'guest@example.com'
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterNeverCapturesGuestEmailForKnownCustomer(): void
    {
        $noRow = $this->createMock(PriceWatchSubscription::class);
        $noRow->method('getId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($noRow));
        $this->productRepository->method('getById')->willReturn($this->makeProduct());

        $noRow->expects(self::once())->method('setGuestEmail')->with(null);

        $this->manager->register(5, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 42, null, 'guest@example.com');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterRefreshesGuestEmailOnExistingRowWhenGiven(): void
    {
        $existing = $this->createMock(PriceWatchSubscription::class);
        $existing->method('getId')->willReturn(7);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));
        $this->productRepository->method('getById')->willReturn($this->makeProduct());

        $existing->expects(self::once())->method('setGuestEmail')->with('new@example.com');

        $this->manager->register(
            5,
            PriceWatchSubscription::WATCH_TYPE_PRICE_DROP,
            null,
            'visitor-1',
            'new@example.com'
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterNeverErasesExistingGuestEmailWhenNoneGiven(): void
    {
        $existing = $this->createMock(PriceWatchSubscription::class);
        $existing->method('getId')->willReturn(7);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));
        $this->productRepository->method('getById')->willReturn($this->makeProduct());

        $existing->expects(self::never())->method('setGuestEmail');

        $this->manager->register(5, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, null, 'visitor-1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterRefreshesExistingRowAndClearsNotifiedAt(): void
    {
        $existing = $this->createMock(PriceWatchSubscription::class);
        $existing->method('getId')->willReturn(7);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));
        $this->productRepository->method('getById')->willReturn($this->makeProduct(50.0, false));

        $existing->expects(self::never())->method('setCreatedAt');
        $existing->expects(self::never())->method('setVisitorId');
        $existing->expects(self::once())->method('setLastKnownPrice')->with(50.0);
        $existing->expects(self::once())->method('setLastKnownInStock')->with(false);
        $existing->expects(self::once())->method('setNotifiedAt')->with(null);
        $this->resource->expects(self::once())->method('save')->with($existing);

        $this->manager->register(5, PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK, null, 'visitor-1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterNeverOverwritesKnownCustomerIdWithNull(): void
    {
        $existing = $this->createMock(PriceWatchSubscription::class);
        $existing->method('getId')->willReturn(7);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));
        $this->productRepository->method('getById')->willReturn($this->makeProduct());

        $existing->expects(self::never())->method('setCustomerId');

        $this->manager->register(5, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, null, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterSetsCustomerIdWhenGiven(): void
    {
        $existing = $this->createMock(PriceWatchSubscription::class);
        $existing->method('getId')->willReturn(7);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));
        $this->productRepository->method('getById')->willReturn($this->makeProduct());

        $existing->expects(self::once())->method('setCustomerId')->with(42);

        $this->manager->register(5, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 42, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnregisterDeletesExistingRow(): void
    {
        $existing = $this->createMock(PriceWatchSubscription::class);
        $existing->method('getId')->willReturn(7);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));

        $this->resource->expects(self::once())->method('delete')->with($existing);

        $this->manager->unregister(5, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 42, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnregisterDoesNothingWhenNoRowExists(): void
    {
        $noRow = $this->createStub(PriceWatchSubscription::class);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($noRow));

        $this->resource->expects(self::never())->method('delete');

        $this->manager->unregister(5, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 42, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAttributeVisitorToCustomerUpdatesEachMatchingRow(): void
    {
        $row = $this->createMock(PriceWatchSubscription::class);
        $collection = $this->makeCollection();
        $collection = $this->createStub(PriceWatchSubscriptionCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addVisitorFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new ArrayIterator([$row]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $row->expects(self::once())->method('setCustomerId')->with(42);
        $this->resource->expects(self::once())->method('save')->with($row);

        $this->manager->attributeVisitorToCustomer('visitor-1', 42);
    }
}
