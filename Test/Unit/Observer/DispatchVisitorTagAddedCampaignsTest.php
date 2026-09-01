<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchVisitorTagAddedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchVisitorTagAddedCampaignsTest extends TestCase
{
    public function testExecutePublishesWhenVisitorAndTagPresent(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['tag', null, 'vip'],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('visitor_tag_added', [
            'visitor_id' => 'v1',
            'tag' => 'vip',
        ]);

        (new DispatchVisitorTagAddedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenTagEmpty(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['tag', null, ''],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchVisitorTagAddedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenVisitorIdEmpty(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['visitor_id', null, ''],
            ['tag', null, 'vip'],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchVisitorTagAddedCampaigns($publisher))->execute($observer);
    }
}
