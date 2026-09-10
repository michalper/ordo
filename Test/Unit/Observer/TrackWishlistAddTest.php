<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Wishlist\Model\Item as WishlistItem;
use Ordo\Automation\Model\VisitorEventLogger;
use Ordo\Automation\Observer\TrackWishlistAdd;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class TrackWishlistAddTest extends TestCase
{
    private function makeItem(?string $sku): WishlistItem
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);

        $item = $this->createStub(WishlistItem::class);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsOneEventPerAddedItem(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(true);
        $customerSession->method('getCustomerId')->willReturn(42);

        $event = new Event(['items' => [$this->makeItem('SKU-1'), $this->makeItem('SKU-2')]]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::exactly(2))->method('log')->willReturnMap([
            ['customer_42', 'wishlist_add', 'SKU-1', 42, null],
            ['customer_42', 'wishlist_add', 'SKU-2', 42, null],
        ]);

        (new TrackWishlistAdd($customerSession, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenNotLoggedIn(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(false);

        $observer = $this->createStub(EventObserver::class);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('log');

        (new TrackWishlistAdd($customerSession, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsItemsWithNoSkuAndNonItemEntries(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(true);
        $customerSession->method('getCustomerId')->willReturn(42);

        $event = new Event(['items' => [$this->makeItem(null), 'not-an-item', $this->makeItem('SKU-1')]]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::once())->method('log')->with('customer_42', 'wishlist_add', 'SKU-1', 42);

        (new TrackWishlistAdd($customerSession, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenEventHasNoItems(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(true);
        $customerSession->method('getCustomerId')->willReturn(42);

        $event = new Event([]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('log');

        (new TrackWishlistAdd($customerSession, $eventLogger))->execute($observer);
    }
}
