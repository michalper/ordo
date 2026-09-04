<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Queue\CampaignDispatchConsumer;
use Ordo\Automation\Model\Queue\CampaignDispatchGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CampaignDispatchConsumerTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDecodesMessageAndDispatches(): void
    {
        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $dispatchGuard = new CampaignDispatchGuard();

        $serializer->method('unserialize')->willReturnMap([
            ['raw-message', [
                'trigger_event' => 'order_placed',
                'context' => ['customer_id' => 42],
            ]],
        ]);

        $dispatcher->expects(self::once())->method('dispatch')->with('order_placed', ['customer_id' => 42]);
        $logger->expects(self::never())->method('error');

        (new CampaignDispatchConsumer($dispatcher, $serializer, $logger, $dispatchGuard))->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsDispatchWhenTriggerEventMissing(): void
    {
        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $dispatchGuard = new CampaignDispatchGuard();

        $serializer->method('unserialize')->willReturn(['context' => []]);

        $dispatcher->expects(self::never())->method('dispatch');
        $logger->expects(self::once())->method('error');

        (new CampaignDispatchConsumer($dispatcher, $serializer, $logger, $dispatchGuard))->execute('raw-message');
    }

    /**
     * The whole point of CampaignDispatchGuard — see CampaignDispatchPublisher's class doc for
     * the real-CI self-deadlock this prevents. Confirms the flag is actually true for the
     * duration of dispatch() (asserted from inside the mocked dispatch() call itself) and false
     * again once execute() returns, success or not.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testFlagsDispatchGuardForTheDurationOfDispatchOnly(): void
    {
        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $dispatchGuard = new CampaignDispatchGuard();

        $serializer->method('unserialize')->willReturn([
            'trigger_event' => 'order_placed',
            'context' => [],
        ]);

        self::assertFalse($dispatchGuard->isConsuming());

        $dispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(function () use ($dispatchGuard): void {
                self::assertTrue($dispatchGuard->isConsuming());
            });

        (new CampaignDispatchConsumer($dispatcher, $serializer, $logger, $dispatchGuard))->execute('raw-message');

        self::assertFalse($dispatchGuard->isConsuming());
    }
}
