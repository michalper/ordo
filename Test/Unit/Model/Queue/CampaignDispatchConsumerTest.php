<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\CampaignDispatchDeadLetter;
use Ordo\Automation\Model\CampaignDispatchDeadLetterFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Queue\CampaignDispatchConsumer;
use Ordo\Automation\Model\Queue\CampaignDispatchGuard;
use Ordo\Automation\Model\ResourceModel\CampaignDispatchDeadLetter as CampaignDispatchDeadLetterResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CampaignDispatchConsumerTest extends TestCase
{
    private CampaignDispatchDeadLetterFactory $deadLetterFactory;
    private CampaignDispatchDeadLetterResource $deadLetterResource;

    protected function setUp(): void
    {
        $this->deadLetterFactory = $this->createStub(CampaignDispatchDeadLetterFactory::class);
        $this->deadLetterFactory->method('create')->willReturn($this->createStub(CampaignDispatchDeadLetter::class));
        $this->deadLetterResource = $this->createStub(CampaignDispatchDeadLetterResource::class);
    }

    private function makeConsumer(
        CampaignDispatcher $dispatcher,
        SerializerInterface $serializer,
        LoggerInterface $logger,
        CampaignDispatchGuard $dispatchGuard
    ): CampaignDispatchConsumer {
        return new CampaignDispatchConsumer(
            $dispatcher,
            $serializer,
            $logger,
            $dispatchGuard,
            $this->deadLetterFactory,
            $this->deadLetterResource
        );
    }

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

        $this->makeConsumer($dispatcher, $serializer, $logger, $dispatchGuard)->execute('raw-message');
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

        $this->makeConsumer($dispatcher, $serializer, $logger, $dispatchGuard)->execute('raw-message');
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

        $this->makeConsumer($dispatcher, $serializer, $logger, $dispatchGuard)->execute('raw-message');

        self::assertFalse($dispatchGuard->isConsuming());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeadLettersAnUndecodableMessageInsteadOfThrowing(): void
    {
        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $dispatchGuard = new CampaignDispatchGuard();

        $serializer->method('unserialize')->willThrowException(new \InvalidArgumentException('bad json'));
        $dispatcher->expects(self::never())->method('dispatch');
        $logger->expects(self::once())->method('error');

        $deadLetter = $this->createMock(CampaignDispatchDeadLetter::class);
        $deadLetter->expects(self::once())->method('setTriggerEvent')->with(null);
        $deadLetter->expects(self::once())->method('setMessage')->with('raw-message');
        $deadLetter->expects(self::once())->method('setError')->with('bad json');
        $this->deadLetterFactory = $this->createStub(CampaignDispatchDeadLetterFactory::class);
        $this->deadLetterFactory->method('create')->willReturn($deadLetter);
        $this->deadLetterResource = $this->createMock(CampaignDispatchDeadLetterResource::class);
        $this->deadLetterResource->expects(self::once())->method('save')->with($deadLetter);

        $this->makeConsumer($dispatcher, $serializer, $logger, $dispatchGuard)->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeadLettersWhenDispatchThrowsInsteadOfPropagating(): void
    {
        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $dispatchGuard = new CampaignDispatchGuard();

        $serializer->method('unserialize')->willReturn([
            'trigger_event' => 'order_placed',
            'context' => [],
        ]);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('boom'));
        $logger->expects(self::once())->method('error');

        $deadLetter = $this->createMock(CampaignDispatchDeadLetter::class);
        $deadLetter->expects(self::once())->method('setTriggerEvent')->with('order_placed');
        $deadLetter->expects(self::once())->method('setError')->with('boom');
        $this->deadLetterFactory = $this->createStub(CampaignDispatchDeadLetterFactory::class);
        $this->deadLetterFactory->method('create')->willReturn($deadLetter);
        $this->deadLetterResource = $this->createMock(CampaignDispatchDeadLetterResource::class);
        $this->deadLetterResource->expects(self::once())->method('save')->with($deadLetter);

        // Must not throw - the whole point of this test.
        $this->makeConsumer($dispatcher, $serializer, $logger, $dispatchGuard)->execute('raw-message');

        self::assertFalse($dispatchGuard->isConsuming());
    }

    public function testExecuteSwallowsAndLogsWhenPersistingTheDeadLetterItselfFails(): void
    {
        $dispatcher = $this->createStub(CampaignDispatcher::class);
        $serializer = $this->createStub(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $dispatchGuard = new CampaignDispatchGuard();

        $serializer->method('unserialize')->willReturn([
            'trigger_event' => 'order_placed',
            'context' => [],
        ]);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('boom'));
        // Once for the original dispatch failure, once for the dead-letter persistence failure.
        $logger->expects(self::exactly(2))->method('error');

        $this->deadLetterFactory = $this->createStub(CampaignDispatchDeadLetterFactory::class);
        $this->deadLetterFactory->method('create')->willReturn($this->createStub(CampaignDispatchDeadLetter::class));
        $this->deadLetterResource = $this->createStub(CampaignDispatchDeadLetterResource::class);
        $this->deadLetterResource->method('save')->willThrowException(new \RuntimeException('db down'));

        // Must not throw or resurface the original dispatch exception - a DB hiccup persisting the
        // dead letter is not allowed to crash the consumer.
        $this->makeConsumer($dispatcher, $serializer, $logger, $dispatchGuard)->execute('raw-message');

        self::assertFalse($dispatchGuard->isConsuming());
    }
}
