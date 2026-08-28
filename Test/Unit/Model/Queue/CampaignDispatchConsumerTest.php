<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Queue\CampaignDispatchConsumer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class CampaignDispatchConsumerTest extends TestCase
{
    public function testExecuteDecodesMessageAndDispatches(): void
    {
        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->with('raw-message')->willReturn([
            'trigger_event' => 'order_placed',
            'context' => ['customer_id' => 42],
        ]);

        $dispatcher->expects(self::once())->method('dispatch')->with('order_placed', ['customer_id' => 42]);
        $logger->expects(self::never())->method('error');

        (new CampaignDispatchConsumer($dispatcher, $serializer, $logger))->execute('raw-message');
    }

    public function testExecuteLogsAndSkipsDispatchWhenTriggerEventMissing(): void
    {
        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->willReturn(['context' => []]);

        $dispatcher->expects(self::never())->method('dispatch');
        $logger->expects(self::once())->method('error');

        (new CampaignDispatchConsumer($dispatcher, $serializer, $logger))->execute('raw-message');
    }
}
