<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchExitIntentCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchExitIntentCampaignsTest extends TestCase
{
    public function testExecutePublishesWhenVisitorIdPresent(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->with('visitor_id')->willReturn('v1');

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('exit_intent', ['visitor_id' => 'v1']);

        (new DispatchExitIntentCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenVisitorIdEmpty(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->with('visitor_id')->willReturn('');

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchExitIntentCampaigns($publisher))->execute($observer);
    }
}
