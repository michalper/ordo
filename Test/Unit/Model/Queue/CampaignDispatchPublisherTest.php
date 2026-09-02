<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Queue\CampaignDispatchGuard;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use PHPUnit\Framework\TestCase;

class CampaignDispatchPublisherTest extends TestCase
{
    public function testPublishSerializesTriggerEventAndContextOntoTheTopic(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::once())
            ->method('serialize')
            ->with(['trigger_event' => 'order_placed', 'context' => ['customer_id' => 42]])
            ->willReturn('{"trigger_event":"order_placed","context":{"customer_id":42}}');

        $publisher->expects(self::once())
            ->method('publish')
            ->with(
                CampaignDispatchPublisher::TOPIC,
                '{"trigger_event":"order_placed","context":{"customer_id":42}}'
            );

        (new CampaignDispatchPublisher($publisher, $serializer, new CampaignDispatchGuard()))
            ->publish('order_placed', ['customer_id' => 42]);
    }

    /**
     * A campaign action (e.g. add_tag) can itself trigger a new publish() to this exact same
     * topic/queue while CampaignDispatchConsumer is still consuming the message that ran it —
     * confirmed via a real CI run to self-deadlock the DB queue driver otherwise (see the class
     * doc). CampaignDispatchGuard::setConsuming(true) is what CampaignDispatchConsumer flags
     * around its dispatch() call; publish() must not call the underlying publisher synchronously
     * in that window — only register_shutdown_function() defers it, which isn't itself
     * practically assertable from a unit test, so "the immediate call never happens" is the
     * behavior this test can actually pin down.
     */
    public function testPublishDefersInsteadOfPublishingImmediatelyWhileConsuming(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $dispatchGuard = new CampaignDispatchGuard();

        $serializer->expects(self::once())->method('serialize')
            ->willReturn('{"trigger_event":"tag_added","context":{"customer_id":1,"tag":"vip"}}');

        $publisher->expects(self::never())->method('publish');

        $dispatchGuard->setConsuming(true);
        (new CampaignDispatchPublisher($publisher, $serializer, $dispatchGuard))
            ->publish('tag_added', ['customer_id' => 1, 'tag' => 'vip']);
    }
}
