<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchOrderRefundedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchOrderRefundedCampaignsTest extends TestCase
{
    public function testExecutePublishesForGenuinelyNewCreditmemo(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        // Deliberately different from the credit memo's own total below - proves
        // 'order_total'/'refund_total' are two separate keys, not one overloaded field.
        $order->method('getBaseGrandTotal')->willReturn(99.5);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getEntityId')->willReturn(123);

        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrigData')->willReturn(null);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getEntityId')->willReturn(77);
        $creditmemo->method('getBaseGrandTotal')->willReturn(40.0);

        $event = new Event(['creditmemo' => $creditmemo]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('order_refunded', [
            'customer_id' => 42,
            'order_total' => 99.5,
            'order_increment_id' => '000000123',
            'order_id' => 123,
            'creditmemo_id' => 77,
            'refund_total' => 40.0,
        ]);

        (new DispatchOrderRefundedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenCreditmemoIsBeingResaved(): void
    {
        $creditmemo = $this->createStub(Creditmemo::class);
        // Already-persisted: getOrigData(ENTITY_ID) is populated, proving this save is a resave
        // (e.g. an admin comment added later), not the credit memo's creation.
        $creditmemo->method('getOrigData')->willReturn(77);

        $event = new Event(['creditmemo' => $creditmemo]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchOrderRefundedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenOrderHasNoCustomer(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(null);

        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrigData')->willReturn(null);
        $creditmemo->method('getOrder')->willReturn($order);

        $event = new Event(['creditmemo' => $creditmemo]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchOrderRefundedCampaigns($publisher))->execute($observer);
    }
}
