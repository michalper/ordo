<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchTagAddedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchTagAddedCampaignsTest extends TestCase
{
    public function testExecutePublishesWhenCustomerAndTagPresent(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['customer_id', null, 42],
            ['tag', null, 'vip'],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with('tag_added', [
            'customer_id' => 42,
            'tag' => 'vip',
        ]);

        (new DispatchTagAddedCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenTagEmpty(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['customer_id', null, 42],
            ['tag', null, ''],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchTagAddedCampaigns($publisher))->execute($observer);
    }
}
