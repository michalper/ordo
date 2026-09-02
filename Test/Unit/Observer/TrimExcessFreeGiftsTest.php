<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Magento\Quote\Model\Quote;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\FreeGiftOfferTier;
use Ordo\Automation\Model\QuoteGiftItem;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection as OfferCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as OfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\Collection as TierCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as TierCollectionFactory;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem as QuoteGiftItemResource;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem\Collection as GiftItemCollection;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem\CollectionFactory as GiftItemCollectionFactory;
use Ordo\Automation\Observer\TrimExcessFreeGifts;
use Ordo\Automation\Test\Unit\QuoteTestDouble;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class TrimExcessFreeGiftsTest extends TestCase
{
    private OfferCollectionFactory $offerCollectionFactory;
    private TierCollectionFactory $tierCollectionFactory;
    private GiftItemCollectionFactory $giftItemCollectionFactory;
    private QuoteGiftItemResource $giftItemResource;
    private Config $config;
    private TrimExcessFreeGifts $observer;

    protected function setUp(): void
    {
        $this->offerCollectionFactory = $this->createMock(OfferCollectionFactory::class);
        $this->tierCollectionFactory = $this->createMock(TierCollectionFactory::class);
        $this->giftItemCollectionFactory = $this->createMock(GiftItemCollectionFactory::class);
        $this->giftItemResource = $this->createMock(QuoteGiftItemResource::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isFreeGiftEnabled')->willReturn(true);

        $this->observer = new TrimExcessFreeGifts(
            $this->offerCollectionFactory,
            $this->tierCollectionFactory,
            $this->giftItemCollectionFactory,
            $this->giftItemResource,
            $this->config
        );
    }

    private function eventObserver(Quote $quote): EventObserver
    {
        $event = new Event(['quote' => $quote]);
        $observer = new EventObserver();
        $observer->setEvent($event);
        return $observer;
    }

    private function giftItemRow(int $quoteItemId): QuoteGiftItem
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $row = new QuoteGiftItem($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $row->setQuoteItemId($quoteItemId);
        return $row;
    }

    private function mockQuote(): Quote
    {
        // getSubtotal() is magic (__call via AbstractModel) on the real Quote class — PHPUnit 12
        // removed addMethods(), the only way to stub a magic method, with no replacement.
        // QuoteTestDouble gives it a real, declared, therefore-mockable-via-onlyMethods()
        // implementation instead. getAllAddresses() is a real declared method already, no
        // double needed for it.
        return $this->getMockBuilder(QuoteTestDouble::class)
            ->onlyMethods(['getId', 'removeItem', 'getStoreId', 'getSubtotal', 'getAllAddresses'])
            ->getMock();
    }

    /**
     * getSubtotal() on Quote\Address is magic too, same reasoning as QuoteTestDouble — the
     * observer under test sums getAllAddresses()'s own getSubtotal() (see its class doc for
     * why, confirmed via a real integration-test run against Quote::collectTotals()'s actual
     * dispatch order), not $quote->getSubtotal() directly.
     */
    private function addressWithSubtotal(float $subtotal): \Magento\Quote\Model\Quote\Address
    {
        $address = new class extends \Magento\Quote\Model\Quote\Address {
            private float $testSubtotal = 0.0;

            public function __construct()
            {
            }

            public function setTestSubtotal(float $subtotal): void
            {
                $this->testSubtotal = $subtotal;
            }

            public function getSubtotal(): float
            {
                return $this->testSubtotal;
            }
        };
        $address->setTestSubtotal($subtotal);
        return $address;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRemovesExcessGiftsWhenSubtotalDropsBelowEarnedSlots(): void
    {
        $offerCollection = $this->createStub(OfferCollection::class);
        $offerCollection->method('addEnabledFilter')->willReturn($offerCollection);
        $offerCollection->method('getAllIds')->willReturn([1]);
        $this->offerCollectionFactory->method('create')->willReturn($offerCollection);

        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $tier = new FreeGiftOfferTier($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $tier->setOfferId(1);
        $tier->setMinSubtotal(0.0);
        $tier->setGiftSlots(1);
        $tierCollection = $this->createStub(TierCollection::class);
        $tierCollection->method('addOffersFilter')->willReturn($tierCollection);
        $tierCollection->method('getIterator')->willReturn(new \ArrayIterator([$tier]));
        $this->tierCollectionFactory->method('create')->willReturn($tierCollection);

        $row1 = $this->giftItemRow(10);
        $row2 = $this->giftItemRow(11);
        $rows = [$row1, $row2];

        $giftCollection = $this->createStub(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(2);
        $giftCollection->method('getItems')->willReturn($rows);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->method('getAllAddresses')->willReturn([$this->addressWithSubtotal(100.0)]);
        $quote->expects(self::once())->method('removeItem')->with(11);

        $this->giftItemResource->expects(self::once())->method('delete')->with($row2);

        $this->observer->execute($this->eventObserver($quote));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNothingWhenNoGiftsInCart(): void
    {
        $giftCollection = $this->createStub(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(0);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->expects(self::never())->method('removeItem');

        $this->observer->execute($this->eventObserver($quote));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkipsQuoteWithoutId(): void
    {
        $this->giftItemCollectionFactory->expects(self::never())->method('create');

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(null);

        $this->observer->execute($this->eventObserver($quote));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTreatsEarnedSlotsAsZeroWhenMasterSwitchDisabled(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isFreeGiftEnabled')->willReturn(false);
        $this->observer = new TrimExcessFreeGifts(
            $this->offerCollectionFactory,
            $this->tierCollectionFactory,
            $this->giftItemCollectionFactory,
            $this->giftItemResource,
            $this->config
        );

        $row = $this->giftItemRow(10);

        $giftCollection = $this->createStub(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(1);
        $giftCollection->method('getItems')->willReturn([$row]);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        // Disabled master switch means earnedSlots() is never called at all.
        $this->offerCollectionFactory->expects(self::never())->method('create');

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->method('getAllAddresses')->willReturn([$this->addressWithSubtotal(1000.0)]);
        $quote->expects(self::once())->method('removeItem')->with(10);

        $this->observer->execute($this->eventObserver($quote));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNothingWhenEarnedSlotsStillCoverCurrentGifts(): void
    {
        $offerCollection = $this->createStub(OfferCollection::class);
        $offerCollection->method('addEnabledFilter')->willReturn($offerCollection);
        $offerCollection->method('getAllIds')->willReturn([1]);
        $this->offerCollectionFactory->method('create')->willReturn($offerCollection);

        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $tier = new FreeGiftOfferTier($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $tier->setOfferId(1);
        $tier->setMinSubtotal(0.0);
        $tier->setGiftSlots(2);
        $tierCollection = $this->createStub(TierCollection::class);
        $tierCollection->method('addOffersFilter')->willReturn($tierCollection);
        $tierCollection->method('getIterator')->willReturn(new \ArrayIterator([$tier]));
        $this->tierCollectionFactory->method('create')->willReturn($tierCollection);

        $giftCollection = $this->createStub(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(2);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->method('getAllAddresses')->willReturn([$this->addressWithSubtotal(100.0)]);
        $quote->expects(self::never())->method('removeItem');

        $this->observer->execute($this->eventObserver($quote));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeletesStaleMarkerRowEvenWhenRemoveItemThrows(): void
    {
        $offerCollection = $this->createStub(OfferCollection::class);
        $offerCollection->method('addEnabledFilter')->willReturn($offerCollection);
        $offerCollection->method('getAllIds')->willReturn([1]);
        $this->offerCollectionFactory->method('create')->willReturn($offerCollection);

        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $tier = new FreeGiftOfferTier($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $tier->setOfferId(1);
        $tier->setMinSubtotal(0.0);
        $tier->setGiftSlots(0);
        $tierCollection = $this->createStub(TierCollection::class);
        $tierCollection->method('addOffersFilter')->willReturn($tierCollection);
        $tierCollection->method('getIterator')->willReturn(new \ArrayIterator([$tier]));
        $this->tierCollectionFactory->method('create')->willReturn($tierCollection);

        $row = $this->giftItemRow(10);

        $giftCollection = $this->createStub(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(1);
        $giftCollection->method('getItems')->willReturn([$row]);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->method('getAllAddresses')->willReturn([$this->addressWithSubtotal(100.0)]);
        $quote->method('removeItem')->willThrowException(new \Exception('item already gone'));

        $this->giftItemResource->expects(self::once())->method('delete')->with($row);

        $this->observer->execute($this->eventObserver($quote));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEarnedSlotsIsZeroWhenNoOffersAreEnabled(): void
    {
        $offerCollection = $this->createStub(OfferCollection::class);
        $offerCollection->method('addEnabledFilter')->willReturn($offerCollection);
        $offerCollection->method('getAllIds')->willReturn([]);
        $this->offerCollectionFactory->method('create')->willReturn($offerCollection);

        $this->tierCollectionFactory->expects(self::never())->method('create');

        $row = $this->giftItemRow(10);

        $giftCollection = $this->createStub(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(1);
        $giftCollection->method('getItems')->willReturn([$row]);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->method('getAllAddresses')->willReturn([$this->addressWithSubtotal(100.0)]);
        $quote->expects(self::once())->method('removeItem')->with(10);

        $this->observer->execute($this->eventObserver($quote));
    }
}
