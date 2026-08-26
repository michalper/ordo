<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Observer\DispatchTagAddedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchTagAddedCampaignsTest extends TestCase
{
    public function testExecuteDispatchesWhenCustomerAndTagPresent(): void
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnMap([
            ['customer_id', null, 42],
            ['tag', null, 'vip'],
        ]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('tag_added', [
            'customer_id' => 42,
            'tag' => 'vip',
        ]);

        (new DispatchTagAddedCampaigns($dispatcher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenTagEmpty(): void
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnMap([
            ['customer_id', null, 42],
            ['tag', null, ''],
        ]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        (new DispatchTagAddedCampaigns($dispatcher))->execute($observer);
    }
}
