<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Queue\SegmentBulkActionConsumer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SegmentBulkActionConsumerTest extends TestCase
{
    private SerializerInterface $serializer;
    private LoggerInterface $logger;

    /** @var array<int, array{customerId: int, params: array<string, mixed>}> */
    private array $executedWith = [];

    protected function setUp(): void
    {
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->executedWith = [];
    }

    private function makeRecordingAction(?callable $onExecute = null): ActionInterface
    {
        $executedWith = &$this->executedWith;

        return new class($executedWith, $onExecute) implements ActionInterface {
            /**
             * @param array<int, array{customerId: int, params: array<string, mixed>}> $executedWith
             */
            public function __construct(
                private array &$executedWith,
                private readonly mixed $onExecute
            ) {
            }

            public function execute(array &$context, array $params): void
            {
                if ($this->onExecute !== null) {
                    ($this->onExecute)((int) $context['customer_id']);
                }
                $this->executedWith[] = ['customerId' => (int) $context['customer_id'], 'params' => $params];
            }
        };
    }

    /**
     * @param array<string, ActionInterface> $actions
     */
    private function makeConsumer(array $actions): SegmentBulkActionConsumer
    {
        return new SegmentBulkActionConsumer(
            new ActionPool($actions),
            $this->serializer,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRunsThePoolActionForEveryCustomerAndLogsSummary(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'segment_id' => 5,
            'action_type' => 'add_tag',
            'params' => ['tag' => 'vip'],
            'customer_ids' => [1, 2, 3],
        ]);

        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: applied bulk action "add_tag" to 3/3 segment members.'
        );

        $this->makeConsumer(['add_tag' => $this->makeRecordingAction()])->execute('raw-message');

        self::assertSame([1, 2, 3], array_column($this->executedWith, 'customerId'));
        self::assertSame(['tag' => 'vip'], $this->executedWith[0]['params']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsPerCustomerFailureAndContinuesTheLoop(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'action_type' => 'add_tag',
            'params' => ['tag' => 'vip'],
            'customer_ids' => [1, 2, 3],
        ]);

        $failing = $this->makeRecordingAction(function (int $customerId): void {
            if ($customerId === 2) {
                throw new \RuntimeException('db down');
            }
        });

        $this->logger->expects(self::once())->method('error');
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: applied bulk action "add_tag" to 2/3 segment members.'
        );

        $this->makeConsumer(['add_tag' => $failing])->execute('raw-message');
    }

    /**
     * An action type the pool doesn't know drops the whole message with one logged error —
     * nothing runs, no per-customer loop, mirroring CampaignDispatcher's fail-closed stance
     * on unknown types.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDropsTheMessageForAnUnrecognizedActionType(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'action_type' => 'delete_everything',
            'params' => [],
            'customer_ids' => [1],
        ]);

        $this->logger->expects(self::once())->method('error')->with(
            self::stringContains('unknown action type "delete_everything"')
        );
        $this->logger->expects(self::never())->method('info');

        $this->makeConsumer(['add_tag' => $this->makeRecordingAction()])->execute('raw-message');

        self::assertSame([], $this->executedWith);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenActionTypeMissing(): void
    {
        $this->serializer->method('unserialize')->willReturn(['customer_ids' => [1]]);

        $this->logger->expects(self::once())->method('error');

        $this->makeConsumer(['add_tag' => $this->makeRecordingAction()])->execute('raw-message');

        self::assertSame([], $this->executedWith);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenCustomerIdsMissing(): void
    {
        $this->serializer->method('unserialize')->willReturn(['action_type' => 'add_tag', 'customer_ids' => []]);

        $this->logger->expects(self::once())->method('error');

        $this->makeConsumer(['add_tag' => $this->makeRecordingAction()])->execute('raw-message');

        self::assertSame([], $this->executedWith);
    }
}
