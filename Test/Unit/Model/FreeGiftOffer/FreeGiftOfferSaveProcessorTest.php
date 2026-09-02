<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\FreeGiftOffer;

use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOffer\FreeGiftOfferSaveProcessor;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\FreeGiftOfferProduct;
use Ordo\Automation\Model\FreeGiftOfferProductFactory;
use Ordo\Automation\Model\FreeGiftOfferTier;
use Ordo\Automation\Model\FreeGiftOfferTierFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct as FreeGiftOfferProductResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\Collection as ProductCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory as ProductCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier as FreeGiftOfferTierResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\Collection as TierCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as TierCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class FreeGiftOfferSaveProcessorTest extends TestCase
{
    private FreeGiftOfferFactory $offerFactory;
    private FreeGiftOfferResource $offerResource;
    private FreeGiftOfferTierFactory $tierFactory;
    private FreeGiftOfferTierResource $tierResource;
    private TierCollectionFactory $tierCollectionFactory;
    private FreeGiftOfferProductFactory $productFactory;
    private FreeGiftOfferProductResource $productResource;
    private ProductCollectionFactory $productCollectionFactory;

    protected function setUp(): void
    {
        $this->offerFactory = $this->createMock(FreeGiftOfferFactory::class);
        $this->offerResource = $this->createMock(FreeGiftOfferResource::class);
        $this->tierFactory = $this->createMock(FreeGiftOfferTierFactory::class);
        $this->tierResource = $this->createMock(FreeGiftOfferTierResource::class);
        $this->tierCollectionFactory = $this->createStub(TierCollectionFactory::class);
        $this->productFactory = $this->createMock(FreeGiftOfferProductFactory::class);
        $this->productResource = $this->createMock(FreeGiftOfferProductResource::class);
        $this->productCollectionFactory = $this->createStub(ProductCollectionFactory::class);
    }

    private function makeProcessor(): FreeGiftOfferSaveProcessor
    {
        return new FreeGiftOfferSaveProcessor(
            $this->offerFactory,
            $this->offerResource,
            $this->tierFactory,
            $this->tierResource,
            $this->tierCollectionFactory,
            $this->productFactory,
            $this->productResource,
            $this->productCollectionFactory
        );
    }

    private function emptyTierCollection(): TierCollection
    {
        $collection = $this->createStub(TierCollection::class);
        $collection->method('addOfferFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    private function emptyProductCollection(): ProductCollection
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('addOfferFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSavesNewOfferWithTiersAndProducts(): void
    {
        $processor = $this->makeProcessor();

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->expects(self::once())->method('setName')->with('Spend more, get more');
        $offer->expects(self::once())->method('setEnabled')->with(true);
        $offer->method('getEntityId')->willReturn(7);
        $this->offerFactory->method('create')->willReturn($offer);

        $this->offerResource->expects(self::once())->method('save')->with($offer);
        $this->offerResource->expects(self::never())->method('load');

        $this->tierCollectionFactory->method('create')->willReturn($this->emptyTierCollection());

        $tier = $this->createMock(FreeGiftOfferTier::class);
        $tier->expects(self::once())->method('setData')->with([
            'offer_id' => 7,
            'min_subtotal' => 100.0,
            'gift_slots' => 1,
        ]);
        $this->tierFactory->method('create')->willReturn($tier);
        $this->tierResource->expects(self::once())->method('save')->with($tier);

        $this->productCollectionFactory->method('create')->willReturn($this->emptyProductCollection());

        $product = $this->createMock(FreeGiftOfferProduct::class);
        $product->expects(self::once())->method('setData')->with([
            'offer_id' => 7,
            'sku' => 'GIFT-MUG',
        ]);
        $this->productFactory->method('create')->willReturn($product);
        $this->productResource->expects(self::once())->method('save')->with($product);

        $result = $processor->process([
            'entity_id' => 0,
            'name' => 'Spend more, get more',
            'enabled' => '1',
            'tiers' => ['tiers' => [['min_subtotal' => '100', 'gift_slots' => '1']]],
            'products' => ['products' => [['sku' => 'GIFT-MUG']]],
        ]);

        self::assertSame($offer, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessLoadsExistingOfferAndDeletesOldChildRows(): void
    {
        $processor = $this->makeProcessor();

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(7);
        $this->offerFactory->method('create')->willReturn($offer);
        $this->offerResource->expects(self::once())->method('load')->with($offer, 7);

        $existingTier = $this->createStub(FreeGiftOfferTier::class);
        $tierCollection = $this->createStub(TierCollection::class);
        $tierCollection->method('addOfferFilter');
        $tierCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingTier]));
        $this->tierCollectionFactory->method('create')->willReturn($tierCollection);
        $this->tierResource->expects(self::once())->method('delete')->with($existingTier);

        $existingProduct = $this->createStub(FreeGiftOfferProduct::class);
        $productCollection = $this->createStub(ProductCollection::class);
        $productCollection->method('addOfferFilter');
        $productCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingProduct]));
        $this->productCollectionFactory->method('create')->willReturn($productCollection);
        $this->productResource->expects(self::once())->method('delete')->with($existingProduct);

        $processor->process(['entity_id' => 7, 'name' => 'Spend more, get more']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSkipsTierAndProductRowsMissingRequiredFields(): void
    {
        $processor = $this->makeProcessor();

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(1);
        $this->offerFactory->method('create')->willReturn($offer);

        $this->tierCollectionFactory->method('create')->willReturn($this->emptyTierCollection());
        $this->productCollectionFactory->method('create')->willReturn($this->emptyProductCollection());

        $this->tierFactory->expects(self::never())->method('create');
        $this->productFactory->expects(self::never())->method('create');

        $processor->process([
            'tiers' => ['tiers' => [['min_subtotal' => '', 'gift_slots' => '1']]],
            'products' => ['products' => [['sku' => '']]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPropagatesExceptionFromSave(): void
    {
        $processor = $this->makeProcessor();

        $offer = $this->createStub(FreeGiftOffer::class);
        $this->offerFactory->method('create')->willReturn($offer);
        $this->offerResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');

        $processor->process(['entity_id' => 3, 'name' => 'Spend more, get more']);
    }
}
