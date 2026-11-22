<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Observer\DispatchSearchNoResultsCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchSearchNoResultsCampaignsTest extends TestCase
{
    public function testExecutePublishesWithCustomerIdWhenPresent(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['visitor_id', 'v1'],
            ['customer_id', 42],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')
            ->with('search_no_results', ['visitor_id' => 'v1', 'customer_id' => 42]);

        (new DispatchSearchNoResultsCampaigns($publisher))->execute($observer);
    }

    public function testExecutePublishesWithoutCustomerIdForAnonymousVisitor(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['visitor_id', 'v1'],
            ['customer_id', null],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')
            ->with('search_no_results', ['visitor_id' => 'v1']);

        (new DispatchSearchNoResultsCampaigns($publisher))->execute($observer);
    }

    public function testExecuteDoesNothingWhenVisitorIdEmpty(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willReturnMap([
            ['visitor_id', ''],
            ['customer_id', null],
        ]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        (new DispatchSearchNoResultsCampaigns($publisher))->execute($observer);
    }
}
