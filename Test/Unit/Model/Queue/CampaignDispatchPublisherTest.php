<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
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

        (new CampaignDispatchPublisher($publisher, $serializer))->publish('order_placed', ['customer_id' => 42]);
    }
}
