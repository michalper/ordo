<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\ActionRetryQueue;
use Ordo\Automation\Model\CampaignActionRetry;
use Ordo\Automation\Model\CampaignActionRetryFactory;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionRetryQueueTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testEnqueuePersistsANewRetryRowAtAttemptOne(): void
    {
        $retry = $this->createMock(CampaignActionRetry::class);
        $retry->expects(self::once())->method('setCampaignId')->with(3);
        $retry->expects(self::once())->method('setResumeActionId')->with(9);
        $retry->expects(self::once())->method('setContext')->with(['customer_id' => 1]);
        $retry->expects(self::once())->method('setAttempts')->with(1);
        $retry->expects(self::once())->method('setLastError')->with('boom');
        $retry->expects(self::once())->method('setNextRetryAt')->with(self::callback('is_string'));

        $factory = $this->createStub(CampaignActionRetryFactory::class);
        $factory->method('create')->willReturn($retry);
        $resource = $this->createMock(CampaignActionRetryResource::class);
        $resource->expects(self::once())->method('save')->with($retry);

        $queue = new ActionRetryQueue($factory, $resource, $this->createStub(LoggerInterface::class));
        $queue->enqueue(3, 9, ['customer_id' => 1], new \RuntimeException('boom'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEnqueueSwallowsAndLogsWhenPersistingFails(): void
    {
        $factory = $this->createStub(CampaignActionRetryFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('db down'));
        $resource = $this->createStub(CampaignActionRetryResource::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $queue = new ActionRetryQueue($factory, $resource, $logger);

        // Must not throw - a failure enqueuing the retry can't be allowed to crash the calling cron.
        $queue->enqueue(3, 9, [], new \RuntimeException('boom'));
    }

    public function testNextRetryAtBacksOffExponentiallyAndCaps(): void
    {
        $factory = $this->createStub(CampaignActionRetryFactory::class);
        $resource = $this->createStub(CampaignActionRetryResource::class);
        $queue = new ActionRetryQueue($factory, $resource, $this->createStub(LoggerInterface::class));

        $attempt1 = strtotime($queue->nextRetryAt(1));
        $attempt2 = strtotime($queue->nextRetryAt(2));
        $attempt10 = strtotime($queue->nextRetryAt(10));

        $now = time();
        self::assertEqualsWithDelta($now + 5 * 60, $attempt1, 5);
        self::assertEqualsWithDelta($now + 10 * 60, $attempt2, 5);
        // Far enough out that the exponential curve would exceed the cap without it.
        self::assertEqualsWithDelta($now + 120 * 60, $attempt10, 5);
    }
}
