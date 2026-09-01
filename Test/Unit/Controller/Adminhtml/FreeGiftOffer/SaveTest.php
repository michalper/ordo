<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\FreeGiftOffer\Save;
use Ordo\Automation\Model\FreeGiftOffer;
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
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
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

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
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

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->offerFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewOfferWithTiersAndProducts(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'entity_id' => 0,
            'name' => 'Spend more, get more',
            'enabled' => '1',
            'tiers' => ['tiers' => [['min_subtotal' => '100', 'gift_slots' => '1']]],
            'products' => ['products' => [['sku' => 'GIFT-MUG']]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->expects(self::once())->method('setName')->with('Spend more, get more');
        $offer->expects(self::once())->method('setEnabled')->with(true);
        $offer->method('getEntityId')->willReturn(7);
        $this->offerFactory->method('create')->willReturn($offer);

        $this->offerResource->expects(self::once())->method('save')->with($offer);

        $emptyTierCollection = $this->createStub(TierCollection::class);
        $emptyTierCollection->method('addOfferFilter');
        $emptyTierCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->tierCollectionFactory->method('create')->willReturn($emptyTierCollection);

        $tier = $this->createMock(FreeGiftOfferTier::class);
        $tier->expects(self::once())->method('setData')->with([
            'offer_id' => 7,
            'min_subtotal' => 100.0,
            'gift_slots' => 1,
        ]);
        $this->tierFactory->method('create')->willReturn($tier);
        $this->tierResource->expects(self::once())->method('save')->with($tier);

        $emptyProductCollection = $this->createStub(ProductCollection::class);
        $emptyProductCollection->method('addOfferFilter');
        $emptyProductCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->productCollectionFactory->method('create')->willReturn($emptyProductCollection);

        $product = $this->createMock(FreeGiftOfferProduct::class);
        $product->expects(self::once())->method('setData')->with([
            'offer_id' => 7,
            'sku' => 'GIFT-MUG',
        ]);
        $this->productFactory->method('create')->willReturn($product);
        $this->productResource->expects(self::once())->method('save')->with($product);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingOfferAndDeletesOldChildRows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'entity_id' => 7,
            'name' => 'Spend more, get more',
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

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

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsTierRowsMissingRequiredFields(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'tiers' => ['tiers' => [['min_subtotal' => '', 'gift_slots' => '1']]],
            'products' => ['products' => [['sku' => '']]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(1);
        $this->offerFactory->method('create')->willReturn($offer);

        $emptyTierCollection = $this->createStub(TierCollection::class);
        $emptyTierCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->tierCollectionFactory->method('create')->willReturn($emptyTierCollection);

        $emptyProductCollection = $this->createStub(ProductCollection::class);
        $emptyProductCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->productCollectionFactory->method('create')->willReturn($emptyProductCollection);

        $this->tierFactory->expects(self::never())->method('create');
        $this->productFactory->expects(self::never())->method('create');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWhenBackParamSet(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['name' => 'Spend more, get more']);
        $this->request->method('getParam')->with('back')->willReturn('1');

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(7);
        $this->offerFactory->method('create')->willReturn($offer);

        $emptyTierCollection = $this->createStub(TierCollection::class);
        $emptyTierCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->tierCollectionFactory->method('create')->willReturn($emptyTierCollection);

        $emptyProductCollection = $this->createStub(ProductCollection::class);
        $emptyProductCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->productCollectionFactory->method('create')->willReturn($emptyProductCollection);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'name' => 'Spend more, get more']);

        $offer = $this->createStub(FreeGiftOffer::class);
        $this->offerFactory->method('create')->willReturn($offer);
        $this->offerResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 3])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }
}
