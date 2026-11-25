<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchOrderPlacedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchOrderPlacedCampaignsTest extends TestCase
{
    public function testExecutePublishesOnTheOrdersFirstEverSave(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        // Deliberately different from getBaseGrandTotal() - proves OrderTotalAtLeast's context
        // is built from the base-currency total, not whatever currency this order was placed in.
        $order->method('getGrandTotal')->willReturn(430.0);
        $order->method('getBaseGrandTotal')->willReturn(99.5);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getEntityId')->willReturn(123);
        // AbstractModel::afterSave() dispatches sales_order_save_after BEFORE updateStoredData()
        // refreshes origData - an unset entity_id here means this save is genuinely the first
        // one (never loaded/saved before).
        $order->method('getOrigData')->willReturnMap([[OrderInterface::ENTITY_ID, null]]);

        $event = new Event(['order' => $order]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('order_placed', [
            'customer_id' => 42,
            'order_total' => 99.5,
            'order_increment_id' => '000000123',
            'order_id' => 123,
        ]);

        (new DispatchOrderPlacedCampaigns($publisher))->execute($observer);
    }

    /**
     * Found during a full-repo /code-review: sales_order_save_after fires on every subsequent
     * save of the same order too (admin edits, status transitions, invoice/shipment/credit-memo
     * creation, order comments) - a populated origData entity_id means this is one of those, not
     * the order's own creation, and must not re-publish/re-dispatch every matching campaign's
     * action chain again.
     */
    public function testExecuteDoesNothingOnALaterSaveOfAnExistingOrder(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getOrigData')->willReturnMap([[OrderInterface::ENTITY_ID, 123]]);

        $event = new Event(['order' => $order]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchOrderPlacedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenOrderHasNoCustomer(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(null);

        $event = new Event(['order' => $order]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchOrderPlacedCampaigns($publisher))->execute($observer);
    }
}
