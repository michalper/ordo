<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\FreeGiftEligibility;
use Ordo\Automation\Model\FreeGiftEligibilityFactory;
use Ordo\Automation\Model\FreeGiftManagement;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOfferProduct;
use Ordo\Automation\Model\FreeGiftOfferTier;
use Ordo\Automation\Model\FreeGiftSelection;
use Ordo\Automation\Model\QuoteGiftItem;
use Ordo\Automation\Model\QuoteGiftItemFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection as OfferCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as OfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\Collection as ProductCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory as ProductCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\Collection as TierCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as TierCollectionFactory;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem as QuoteGiftItemResource;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem\Collection as GiftItemCollection;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem\CollectionFactory as GiftItemCollectionFactory;
use Ordo\Automation\Test\Unit\CatalogProductTestDouble;
use Ordo\Automation\Test\Unit\QuoteItemTestDouble;
use Ordo\Automation\Test\Unit\QuoteTestDouble;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
class FreeGiftManagementTest extends AbstractModelTestCase
{
    private CartRepositoryInterface $cartRepository;
    private OfferCollectionFactory $offerCollectionFactory;
    private TierCollectionFactory $tierCollectionFactory;
    private ProductCollectionFactory $productCollectionFactory;
    private GiftItemCollectionFactory $giftItemCollectionFactory;
    private QuoteGiftItemFactory $giftItemFactory;
    private QuoteGiftItemResource $giftItemResource;
    private FreeGiftEligibilityFactory $eligibilityFactory;
    private ProductRepositoryInterface $productRepository;
    private UserContextInterface $userContext;
    private Config $config;
    private FreeGiftManagement $management;

    protected function setUp(): void
    {
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->offerCollectionFactory = $this->createStub(OfferCollectionFactory::class);
        $this->tierCollectionFactory = $this->createStub(TierCollectionFactory::class);
        $this->productCollectionFactory = $this->createStub(ProductCollectionFactory::class);
        $this->giftItemCollectionFactory = $this->createStub(GiftItemCollectionFactory::class);
        $this->giftItemFactory = $this->createStub(QuoteGiftItemFactory::class);
        $this->giftItemResource = $this->createMock(QuoteGiftItemResource::class);
        $this->eligibilityFactory = $this->createStub(FreeGiftEligibilityFactory::class);
        $this->eligibilityFactory->method('create')->willReturnCallback(fn () => new FreeGiftEligibility());
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->userContext = $this->createStub(UserContextInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isFreeGiftEnabled')->willReturn(true);

        $this->management = new FreeGiftManagement(
            $this->cartRepository,
            $this->offerCollectionFactory,
            $this->tierCollectionFactory,
            $this->productCollectionFactory,
            $this->giftItemCollectionFactory,
            $this->giftItemFactory,
            $this->giftItemResource,
            $this->eligibilityFactory,
            $this->productRepository,
            $this->userContext,
            $this->config
        );
    }

    /**
     * @param \Ordo\Automation\Model\FreeGiftOfferTier[] $tiers
     */
    private function stubOffersAndTiers(array $offerIds, array $tiers): void
    {
        $offerCollection = $this->createStub(OfferCollection::class);
        $offerCollection->method('addEnabledFilter')->willReturn($offerCollection);
        $offerCollection->method('getAllIds')->willReturn($offerIds);
        $this->offerCollectionFactory->method('create')->willReturn($offerCollection);

        $tierCollection = $this->createStub(TierCollection::class);
        $tierCollection->method('addOffersFilter')->willReturn($tierCollection);
        $tierCollection->method('getIterator')->willReturn(new \ArrayIterator($tiers));
        $this->tierCollectionFactory->method('create')->willReturn($tierCollection);
    }

    /**
     * @param \Ordo\Automation\Model\FreeGiftOfferProduct[] $products
     */
    private function stubProducts(array $products): void
    {
        $productCollection = $this->createStub(ProductCollection::class);
        $productCollection->method('addOffersFilter')->willReturn($productCollection);
        $productCollection->method('getIterator')->willReturn(new \ArrayIterator($products));
        $this->productCollectionFactory->method('create')->willReturn($productCollection);
    }

    /**
     * @param string[] $skus
     */
    private function selection(array $skus): FreeGiftSelection
    {
        return (new FreeGiftSelection())->setSkus($skus);
    }

    private function stubGiftItems(array $rows): void
    {
        $giftCollection = $this->createStub(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(count($rows));
        $giftCollection->method('getIterator')->willReturn(new \ArrayIterator($rows));
        $giftCollection->method('getItems')->willReturn($rows);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);
    }

    private function tier(int $offerId, float $minSubtotal, int $giftSlots): FreeGiftOfferTier
    {
        $tier = new FreeGiftOfferTier($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
        $tier->setOfferId($offerId);
        $tier->setMinSubtotal($minSubtotal);
        $tier->setGiftSlots($giftSlots);
        return $tier;
    }

    private function product(int $offerId, string $sku): FreeGiftOfferProduct
    {
        $product = new FreeGiftOfferProduct($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
        $product->setOfferId($offerId);
        $product->setSku($sku);
        return $product;
    }

    private function quote(float $subtotal, int $id = 42, int $customerId = 0, int $storeId = 1): Quote
    {
        // getSubtotal()/getCustomerId() are magic (__call via AbstractModel), not real declared
        // methods on Quote — PHPUnit 12 removed addMethods(), the only way to stub those, with
        // no replacement (a mock's own generated __call() shadows Quote's, so stubbing getData()
        // doesn't route through it either). QuoteTestDouble gives them a real, declared,
        // therefore-mockable implementation instead.
        $quote = $this->getMockBuilder(QuoteTestDouble::class)
            ->onlyMethods(['getId', 'getStoreId', 'collectTotals', 'addProduct', 'removeItem'])
            ->getMock();
        $quote->method('getId')->willReturn($id);
        $quote->setTestSubtotal($subtotal);
        $quote->setTestCustomerId($customerId);
        $quote->method('getStoreId')->willReturn($storeId);
        return $quote;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetEligibilitySumsCascadingTiersAcrossMultipleOffers(): void
    {
        // Offer 1: tier @100 -> +1, tier @300 -> +1 (cumulative -> 2 at subtotal 300)
        // Offer 2: tier @300 -> +1
        $this->stubOffersAndTiers([1, 2], [
            $this->tier(1, 100.0, 1),
            $this->tier(1, 300.0, 1),
            $this->tier(2, 300.0, 1),
        ]);
        $this->stubProducts([$this->product(1, 'SKU-A'), $this->product(2, 'SKU-B')]);
        $this->stubGiftItems([]);

        $quote = $this->quote(300.0);
        $quote->expects(self::atLeastOnce())->method('collectTotals');
        $this->cartRepository->method('get')->with(7)->willReturn($quote);

        $eligibility = $this->management->getEligibility(7);

        self::assertSame(3, $eligibility->getEarnedSlots());
        self::assertSame(0, $eligibility->getUsedSlots());
        self::assertSame(3, $eligibility->getRemainingSlots());
        self::assertEqualsCanonicalizing(['SKU-A', 'SKU-B'], $eligibility->getEligibleSkus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetEligibilityBelowFirstTierEarnsNothing(): void
    {
        $this->stubOffersAndTiers([1], [$this->tier(1, 100.0, 1)]);
        $this->stubProducts([]);
        $this->stubGiftItems([]);

        $quote = $this->quote(50.0);
        $this->cartRepository->method('get')->willReturn($quote);

        $eligibility = $this->management->getEligibility(7);

        self::assertSame(0, $eligibility->getEarnedSlots());
        self::assertSame([], $eligibility->getEligibleSkus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetEligibilityReturnsZeroWhenMasterSwitchDisabled(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isFreeGiftEnabled')->willReturn(false);
        $this->management = new FreeGiftManagement(
            $this->cartRepository,
            $this->offerCollectionFactory,
            $this->tierCollectionFactory,
            $this->productCollectionFactory,
            $this->giftItemCollectionFactory,
            $this->giftItemFactory,
            $this->giftItemResource,
            $this->eligibilityFactory,
            $this->productRepository,
            $this->userContext,
            $this->config
        );
        $this->stubGiftItems([]);

        $quote = $this->quote(1000.0);
        $this->cartRepository->method('get')->willReturn($quote);

        $eligibility = $this->management->getEligibility(7);

        self::assertSame(0, $eligibility->getEarnedSlots());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetEligibilityThrowsWhenCartBelongsToDifferentCustomer(): void
    {
        $this->userContext->method('getUserId')->willReturn(99);
        $quote = $this->quote(0.0, 7, 5);
        $this->cartRepository->method('get')->willReturn($quote);

        $this->expectException(NoSuchEntityException::class);
        $this->management->getEligibility(7);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelectGiftsThrowsOnDuplicateSkus(): void
    {
        $this->expectException(InputException::class);
        $this->management->selectGifts(7, $this->selection(['SKU-A', 'SKU-A']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelectGiftsThrowsWhenExceedingEarnedSlots(): void
    {
        $this->stubOffersAndTiers([1], [$this->tier(1, 100.0, 1)]);
        $this->stubProducts([$this->product(1, 'SKU-A')]);
        $this->stubGiftItems([]);

        $quote = $this->quote(150.0);
        $this->cartRepository->method('get')->willReturn($quote);

        $this->expectException(LocalizedException::class);
        $this->management->selectGifts(7, $this->selection(['SKU-A', 'SKU-B']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelectGiftsThrowsOnIneligibleSku(): void
    {
        $this->stubOffersAndTiers([1], [$this->tier(1, 100.0, 2)]);
        $this->stubProducts([$this->product(1, 'SKU-A')]);
        $this->stubGiftItems([]);

        $quote = $this->quote(150.0);
        $this->cartRepository->method('get')->willReturn($quote);

        $this->expectException(InputException::class);
        $this->management->selectGifts(7, $this->selection(['SKU-NOT-IN-POOL']));
    }

    public function testSelectGiftsAddsItemsWithZeroPriceAndPersistsMarkerRows(): void
    {
        $this->stubOffersAndTiers([1], [$this->tier(1, 100.0, 1)]);
        $this->stubProducts([$this->product(1, 'SKU-A')]);
        $this->stubGiftItems([]);

        $quote = $this->quote(150.0);
        $this->cartRepository->method('get')->willReturn($quote);
        $this->cartRepository->expects(self::once())->method('save')->with($quote);

        $product = $this->createStub(Product::class);
        $this->productRepository->method('get')->with('SKU-A')->willReturn($product);

        // setOriginalCustomPrice()/setIsSuperMode() are magic (__call via AbstractModel), not
        // real declared methods — PHPUnit 12 removed addMethods(), the only way to stub those,
        // with no replacement. The TestDoubles give them a real, declared implementation and
        // state is asserted afterward instead of via expects()->with().
        $item = $this->getMockBuilder(QuoteItemTestDouble::class)
            ->onlyMethods(['setCustomPrice', 'getId', 'getProduct'])
            ->getMock();
        $item->expects(self::once())->method('setCustomPrice')->with(0.0)->willReturnSelf();
        $item->method('getId')->willReturn(101);
        $itemProduct = new CatalogProductTestDouble();
        $item->method('getProduct')->willReturn($itemProduct);

        $quote->expects(self::once())->method('addProduct')->with($product, 1)->willReturn($item);

        $giftItem = new QuoteGiftItem($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
        $this->giftItemFactory->method('create')->willReturn($giftItem);
        $this->giftItemResource->expects(self::once())->method('save')->with($giftItem);

        $eligibility = $this->management->selectGifts(7, $this->selection(['SKU-A']));

        self::assertSame(1, $eligibility->getEarnedSlots());
        self::assertSame(0.0, $item->getTestOriginalCustomPrice());
        self::assertTrue($itemProduct->getTestIsSuperMode());
        self::assertSame(42, $giftItem->getQuoteId());
        self::assertSame(101, $giftItem->getQuoteItemId());
        self::assertSame(1, $giftItem->getOfferId());
        self::assertSame('SKU-A', $giftItem->getSku());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetEligibilityReturnsZeroWhenNoOffersAreEnabled(): void
    {
        $this->stubOffersAndTiers([], []);
        $this->stubGiftItems([]);

        $quote = $this->quote(1000.0);
        $this->cartRepository->method('get')->willReturn($quote);

        $eligibility = $this->management->getEligibility(7);

        self::assertSame(0, $eligibility->getEarnedSlots());
        self::assertSame([], $eligibility->getEligibleSkus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelectGiftsThrowsWhenAddProductReturnsAnErrorString(): void
    {
        $this->stubOffersAndTiers([1], [$this->tier(1, 100.0, 1)]);
        $this->stubProducts([$this->product(1, 'SKU-A')]);
        $this->stubGiftItems([]);

        $quote = $this->quote(150.0);
        $this->cartRepository->method('get')->willReturn($quote);

        $product = $this->createStub(Product::class);
        $this->productRepository->method('get')->with('SKU-A')->willReturn($product);
        $quote->method('addProduct')->with($product, 1)->willReturn('Not enough stock.');

        $this->expectException(LocalizedException::class);
        $this->management->selectGifts(7, $this->selection(['SKU-A']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelectGiftsRemovesStaleMarkerRowEvenWhenQuoteItemAlreadyGone(): void
    {
        $this->stubOffersAndTiers([1], [$this->tier(1, 100.0, 1)]);
        $this->stubProducts([$this->product(1, 'SKU-A')]);

        $staleRow = new QuoteGiftItem($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
        $staleRow->setQuoteItemId(999);
        $this->stubGiftItems([$staleRow]);

        $quote = $this->quote(150.0);
        $this->cartRepository->method('get')->willReturn($quote);
        $quote->method('removeItem')->with(999)->willThrowException(new \Exception('item already gone'));

        // The stale marker row must still be deleted even though removeItem() failed.
        $this->giftItemResource->expects(self::once())->method('delete')->with($staleRow);

        $product = $this->createStub(Product::class);
        $this->productRepository->method('get')->with('SKU-A')->willReturn($product);

        $item = $this->getMockBuilder(QuoteItemTestDouble::class)
            ->onlyMethods(['setCustomPrice', 'getId', 'getProduct'])
            ->getMock();
        $item->method('setCustomPrice')->willReturnSelf();
        $item->method('getId')->willReturn(101);
        $itemProduct = new CatalogProductTestDouble();
        $item->method('getProduct')->willReturn($itemProduct);
        $quote->method('addProduct')->willReturn($item);

        $newGiftItem = new QuoteGiftItem($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
        $this->giftItemFactory->method('create')->willReturn($newGiftItem);

        $this->management->selectGifts(7, $this->selection(['SKU-A']));
    }
}
