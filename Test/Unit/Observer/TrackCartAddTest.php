<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\VisitorEventLogger;
use Ordo\Automation\Observer\TrackCartAdd;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class TrackCartAddTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsACartAddEventForALoggedInCustomer(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(true);
        $customerSession->method('getCustomerId')->willReturn(42);

        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('SKU-1');

        $event = new Event(['product' => $product]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::once())->method('log')->with('customer_42', 'cart_add', 'SKU-1', 42);

        (new TrackCartAdd($customerSession, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenNotLoggedIn(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(false);

        $observer = $this->createStub(EventObserver::class);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('log');

        (new TrackCartAdd($customerSession, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenProductHasNoSku(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(true);
        $customerSession->method('getCustomerId')->willReturn(42);

        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn(null);

        $event = new Event(['product' => $product]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('log');

        (new TrackCartAdd($customerSession, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenEventHasNoProduct(): void
    {
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(true);
        $customerSession->method('getCustomerId')->willReturn(42);

        $event = new Event([]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('log');

        (new TrackCartAdd($customerSession, $eventLogger))->execute($observer);
    }
}
