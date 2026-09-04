<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Model\VisitorEventLogger;
use Ordo\Automation\Observer\StitchVisitorIdentity;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class StitchVisitorIdentityTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAttributesVisitorWhenCookieAndCustomerPresent(): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnMap([[StitchVisitorIdentity::COOKIE_NAME, 'v1']]);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $event = new Event(['customer' => $customer]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::once())->method('attributeVisitorToCustomer')->with('v1', 42);

        (new StitchVisitorIdentity($cookieManager, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenNoCookie(): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn(null);

        $observer = $this->createStub(EventObserver::class);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('attributeVisitorToCustomer');

        (new StitchVisitorIdentity($cookieManager, $eventLogger))->execute($observer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenNoCustomer(): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn('v1');

        $event = new Event([]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $eventLogger = $this->createMock(VisitorEventLogger::class);
        $eventLogger->expects(self::never())->method('attributeVisitorToCustomer');

        (new StitchVisitorIdentity($cookieManager, $eventLogger))->execute($observer);
    }
}
