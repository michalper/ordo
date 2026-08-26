<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Model\VisitorAggregator;
use Ordo\Automation\Model\VisitorEventLogger;
use Ordo\Automation\Observer\StitchVisitorIdentity;
use PHPUnit\Framework\TestCase;

class StitchVisitorIdentityTest extends TestCase
{
    public function testExecuteStitchesAndAggregatesWhenCookieAndCustomerPresent(): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->with(StitchVisitorIdentity::COOKIE_NAME)->willReturn('v1');

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $event = new Event(['customer' => $customer]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::once())->method('attributeVisitorToCustomer')->with('v1', 42);

        $aggregator = $this->createMock(VisitorAggregator::class);
        $aggregator->expects(self::once())->method('aggregateForCustomer')->with(42);

        (new StitchVisitorIdentity($cookieManager, $eventLogger, $aggregator))->execute($observer);
    }

    public function testExecuteDoesNothingWhenNoCookie(): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn(null);

        $observer = $this->createMock(EventObserver::class);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('attributeVisitorToCustomer');

        $aggregator = $this->createMock(VisitorAggregator::class);
        $aggregator->expects(self::never())->method('aggregateForCustomer');

        (new StitchVisitorIdentity($cookieManager, $eventLogger, $aggregator))->execute($observer);
    }

    public function testExecuteDoesNothingWhenNoCustomer(): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn('v1');

        $event = new Event([]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('attributeVisitorToCustomer');

        $aggregator = $this->createMock(VisitorAggregator::class);

        (new StitchVisitorIdentity($cookieManager, $eventLogger, $aggregator))->execute($observer);
    }
}
