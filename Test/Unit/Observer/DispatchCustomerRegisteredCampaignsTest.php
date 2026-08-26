<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Observer\DispatchCustomerRegisteredCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchCustomerRegisteredCampaignsTest extends TestCase
{
    public function testExecuteDispatchesForKnownCustomer(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $event = new Event(['customer' => $customer]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('customer_registered', ['customer_id' => 42]);

        (new DispatchCustomerRegisteredCampaigns($dispatcher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenCustomerMissing(): void
    {
        $event = new Event([]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        (new DispatchCustomerRegisteredCampaigns($dispatcher))->execute($observer);
    }
}
