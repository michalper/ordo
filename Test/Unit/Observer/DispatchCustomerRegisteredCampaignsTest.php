<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchCustomerRegisteredCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchCustomerRegisteredCampaignsTest extends TestCase
{
    public function testExecutePublishesForKnownCustomer(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $event = new Event(['customer' => $customer]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('customer_registered', ['customer_id' => 42]);

        (new DispatchCustomerRegisteredCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenCustomerMissing(): void
    {
        $event = new Event([]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchCustomerRegisteredCampaigns($publisher))->execute($observer);
    }
}
