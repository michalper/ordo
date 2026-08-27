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
use PHPUnit\Framework\TestCase;

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
        $this->config = $this->createMock(Config::class);
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

    /**
     * getSubtotal() is magic (__call via AbstractModel), not a real declared method on Quote —
     * createMock() alone can't stub it, needs addMethods().
     */
    private function mockQuote(): Quote
    {
        return $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'removeItem', 'getStoreId'])
            ->addMethods(['getSubtotal'])
            ->getMock();
    }

    public function testRemovesExcessGiftsWhenSubtotalDropsBelowEarnedSlots(): void
    {
        $offerCollection = $this->createMock(OfferCollection::class);
        $offerCollection->method('addEnabledFilter')->willReturn($offerCollection);
        $offerCollection->method('getAllIds')->willReturn([1]);
        $this->offerCollectionFactory->method('create')->willReturn($offerCollection);

        $resource = $this->createMock(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $tier = new FreeGiftOfferTier($this->createMock(Context::class), $this->createMock(Registry::class), $resource);
        $tier->setOfferId(1);
        $tier->setMinSubtotal(0.0);
        $tier->setGiftSlots(1);
        $tierCollection = $this->createMock(TierCollection::class);
        $tierCollection->method('addOffersFilter')->willReturn($tierCollection);
        $tierCollection->method('getIterator')->willReturn(new \ArrayIterator([$tier]));
        $this->tierCollectionFactory->method('create')->willReturn($tierCollection);

        $row1 = $this->getMockBuilder(QuoteGiftItem::class)->disableOriginalConstructor()->addMethods(['getQuoteItemId'])->getMock();
        $row1->method('getQuoteItemId')->willReturn(10);
        $row2 = $this->getMockBuilder(QuoteGiftItem::class)->disableOriginalConstructor()->addMethods(['getQuoteItemId'])->getMock();
        $row2->method('getQuoteItemId')->willReturn(11);
        $rows = [$row1, $row2];

        $giftCollection = $this->createMock(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(2);
        $giftCollection->method('getItems')->willReturn($rows);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->method('getSubtotal')->willReturn(100.0);
        $quote->expects(self::once())->method('removeItem')->with(11);

        $this->giftItemResource->expects(self::once())->method('delete')->with($row2);

        $this->observer->execute($this->eventObserver($quote));
    }

    public function testDoesNothingWhenNoGiftsInCart(): void
    {
        $giftCollection = $this->createMock(GiftItemCollection::class);
        $giftCollection->method('addQuoteFilter')->willReturn($giftCollection);
        $giftCollection->method('getSize')->willReturn(0);
        $this->giftItemCollectionFactory->method('create')->willReturn($giftCollection);

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(42);
        $quote->expects(self::never())->method('removeItem');

        $this->observer->execute($this->eventObserver($quote));
    }

    public function testSkipsQuoteWithoutId(): void
    {
        $this->giftItemCollectionFactory->expects(self::never())->method('create');

        $quote = $this->mockQuote();
        $quote->method('getId')->willReturn(null);

        $this->observer->execute($this->eventObserver($quote));
    }
}
