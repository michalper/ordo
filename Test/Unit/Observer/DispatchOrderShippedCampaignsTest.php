<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchOrderShippedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchOrderShippedCampaignsTest extends TestCase
{
    public function testExecutePublishesForGenuinelyNewShipmentWithTracks(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getBaseGrandTotal')->willReturn(99.5);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getEntityId')->willReturn(123);

        $track1 = $this->createStub(ShipmentTrackInterface::class);
        $track1->method('getTrackNumber')->willReturn('1Z999AA1');
        $track1->method('getTitle')->willReturn('UPS');

        $track2 = $this->createStub(ShipmentTrackInterface::class);
        $track2->method('getTrackNumber')->willReturn('1Z999AA2');
        $track2->method('getTitle')->willReturn('FedEx');

        $shipment = $this->createStub(Shipment::class);
        $shipment->method('getOrigData')->willReturn(null);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getEntityId')->willReturn(55);
        $shipment->method('getTracks')->willReturn([$track1, $track2]);

        $event = new Event(['shipment' => $shipment]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('order_shipped', [
            'customer_id' => 42,
            'order_total' => 99.5,
            'order_increment_id' => '000000123',
            'order_id' => 123,
            'shipment_id' => 55,
            'tracking_numbers' => '1Z999AA1, 1Z999AA2',
            'carrier_titles' => 'UPS, FedEx',
        ]);

        (new DispatchOrderShippedCampaigns($publisher))->execute($observer);
    }

    public function testExecutePublishesWithEmptyTrackingWhenShipmentHasNoTracks(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getBaseGrandTotal')->willReturn(99.5);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getEntityId')->willReturn(123);

        $shipment = $this->createStub(Shipment::class);
        $shipment->method('getOrigData')->willReturn(null);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getEntityId')->willReturn(55);
        $shipment->method('getTracks')->willReturn([]);

        $event = new Event(['shipment' => $shipment]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('order_shipped', [
            'customer_id' => 42,
            'order_total' => 99.5,
            'order_increment_id' => '000000123',
            'order_id' => 123,
            'shipment_id' => 55,
            'tracking_numbers' => '',
            'carrier_titles' => '',
        ]);

        (new DispatchOrderShippedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenShipmentIsBeingResaved(): void
    {
        $shipment = $this->createStub(Shipment::class);
        // A real, already-persisted entity: getOrigData(ENTITY_ID) is populated, proving this
        // save is a resave (e.g. a tracking number added later), not the shipment's creation.
        $shipment->method('getOrigData')->willReturn(55);

        $event = new Event(['shipment' => $shipment]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchOrderShippedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenOrderHasNoCustomer(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(null);

        $shipment = $this->createStub(Shipment::class);
        $shipment->method('getOrigData')->willReturn(null);
        $shipment->method('getOrder')->willReturn($order);

        $event = new Event(['shipment' => $shipment]);
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchOrderShippedCampaigns($publisher))->execute($observer);
    }
}
