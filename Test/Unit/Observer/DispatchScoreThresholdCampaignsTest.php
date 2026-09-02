<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchScoreThresholdCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchScoreThresholdCampaignsTest extends TestCase
{
    public function testExecutePublishesWhenCustomerPresent(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([['customer_id', null, 42]]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('score_threshold_crossed', [
            'customer_id' => 42,
        ]);

        (new DispatchScoreThresholdCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenCustomerIdMissing(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([['customer_id', null, null]]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchScoreThresholdCampaigns($publisher))->execute($observer);
    }
}
