<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use Ordo\Automation\Observer\RecordCampaignOutcome;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class RecordCampaignOutcomeTest extends TestCase
{
    private function makeEventObserver(?Order $order): EventObserver
    {
        $event = new Event(['order' => $order]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteMarksActedWhenOrderHasCustomer(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getEntityId')->willReturn(7);

        $campaignOutcomeLogger = $this->createMock(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->expects(self::once())->method('markActed')->with(42, 7);

        (new RecordCampaignOutcome($campaignOutcomeLogger))->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenOrderMissing(): void
    {
        $campaignOutcomeLogger = $this->createMock(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->expects(self::never())->method('markActed');

        (new RecordCampaignOutcome($campaignOutcomeLogger))->execute($this->makeEventObserver(null));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenOrderHasNoCustomer(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(null);

        $campaignOutcomeLogger = $this->createMock(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->expects(self::never())->method('markActed');

        (new RecordCampaignOutcome($campaignOutcomeLogger))->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenOrderHasNoEntityId(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getEntityId')->willReturn(null);

        $campaignOutcomeLogger = $this->createMock(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->expects(self::never())->method('markActed');

        (new RecordCampaignOutcome($campaignOutcomeLogger))->execute($this->makeEventObserver($order));
    }
}
