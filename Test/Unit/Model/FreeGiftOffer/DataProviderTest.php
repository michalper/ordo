<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\FreeGiftOffer;

use Magento\Framework\App\Request\DataPersistorInterface;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOffer\DataProvider;
use Ordo\Automation\Model\FreeGiftOfferProduct;
use Ordo\Automation\Model\FreeGiftOfferTier;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection as OfferCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as OfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\Collection as ProductCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory as ProductCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\Collection as TierCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as TierCollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class DataProviderTest extends TestCase
{
    private TierCollectionFactory $tierCollectionFactory;
    private ProductCollectionFactory $productCollectionFactory;
    private DataPersistorInterface $dataPersistor;

    protected function setUp(): void
    {
        $this->tierCollectionFactory = $this->createStub(TierCollectionFactory::class);
        $this->productCollectionFactory = $this->createStub(ProductCollectionFactory::class);
        $this->dataPersistor = $this->createMock(DataPersistorInterface::class);
    }

    private function makeProvider(OfferCollection $collection): DataProvider
    {
        $collectionFactory = $this->createStub(OfferCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new DataProvider(
            'ordo_free_gift_offer_form_data_source',
            'entity_id',
            'entity_id',
            $collectionFactory,
            $this->tierCollectionFactory,
            $this->productCollectionFactory,
            $this->dataPersistor
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDataNestsTiersAndProducts(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $offer->method('getData')->willReturn(['entity_id' => 1, 'name' => 'Spend more, get more']);
        $offer->method('getEntityId')->willReturn(1);

        $collection = $this->createStub(OfferCollection::class);
        $collection->method('getItems')->willReturn([$offer]);

        $tier = $this->createStub(FreeGiftOfferTier::class);
        $tier->method('getMinSubtotal')->willReturn(100.0);
        $tier->method('getGiftSlots')->willReturn(1);
        $tierCollection = $this->createStub(TierCollection::class);
        $tierCollection->method('addOfferFilter');
        $tierCollection->method('getItems')->willReturn([$tier]);
        $this->tierCollectionFactory->method('create')->willReturn($tierCollection);

        $product = $this->createStub(FreeGiftOfferProduct::class);
        $product->method('getSku')->willReturn('GIFT-MUG');
        $productCollection = $this->createStub(ProductCollection::class);
        $productCollection->method('addOfferFilter');
        $productCollection->method('getItems')->willReturn([$product]);
        $this->productCollectionFactory->method('create')->willReturn($productCollection);

        $this->dataPersistor->method('get')->willReturn(null);

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['min_subtotal' => 100.0, 'gift_slots' => 1], $data[1]['tiers'][0]);
        self::assertSame(['sku' => 'GIFT-MUG'], $data[1]['products'][0]);

        // Second call must hit the cached $loadedData branch, not reload from the collection.
        self::assertSame($data, $provider->getData());
    }

    public function testGetDataAppliesPersistedDataAndClearsIt(): void
    {
        $collection = $this->createStub(OfferCollection::class);
        $collection->method('getItems')->willReturn([]);

        $emptyTierCollection = $this->createStub(TierCollection::class);
        $emptyTierCollection->method('getItems')->willReturn([]);
        $this->tierCollectionFactory->method('create')->willReturn($emptyTierCollection);

        $emptyProductCollection = $this->createStub(ProductCollection::class);
        $emptyProductCollection->method('getItems')->willReturn([]);
        $this->productCollectionFactory->method('create')->willReturn($emptyProductCollection);

        $this->dataPersistor->method('get')->willReturnMap([['ordo_free_gift_offer', ['entity_id' => 5, 'name' => 'Draft']]]);
        $this->dataPersistor->expects(self::once())->method('clear')->with('ordo_free_gift_offer');

        $provider = $this->makeProvider($collection);
        $data = $provider->getData();

        self::assertSame(['entity_id' => 5, 'name' => 'Draft'], $data[5]);
    }
}
