<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Observer\DispatchOrderPlacedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchOrderPlacedCampaignsTest extends TestCase
{
    public function testExecuteDispatchesForOrderWithCustomer(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getGrandTotal')->willReturn(99.5);
        $order->method('getIncrementId')->willReturn('000000123');

        $event = new Event(['order' => $order]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('order_placed', [
            'customer_id' => 42,
            'order_total' => 99.5,
            'order_increment_id' => '000000123',
        ]);

        (new DispatchOrderPlacedCampaigns($dispatcher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenOrderHasNoCustomer(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(null);

        $event = new Event(['order' => $order]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        (new DispatchOrderPlacedCampaigns($dispatcher))->execute($observer);
    }
}
