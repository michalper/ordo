<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Cron\Model\Schedule as CronSchedule;
use Magento\Cron\Model\ScheduleFactory as CronScheduleFactory;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\Campaign\ScheduledTriggerScanner;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledTriggerState;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ScheduledTriggerScannerTest extends TestCase
{
    private TriggerCollectionFactory $triggerCollectionFactory;
    private ScheduledTriggerState $scheduledTriggerState;
    private CampaignDispatcher $campaignDispatcher;
    private CronScheduleFactory $cronScheduleFactory;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->scheduledTriggerState = $this->createMock(ScheduledTriggerState::class);
        $this->campaignDispatcher = $this->createMock(CampaignDispatcher::class);
        $this->cronScheduleFactory = $this->createMock(CronScheduleFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeScanner(): ScheduledTriggerScanner
    {
        return new ScheduledTriggerScanner(
            $this->triggerCollectionFactory,
            $this->scheduledTriggerState,
            $this->campaignDispatcher,
            $this->cronScheduleFactory,
            $this->logger
        );
    }

    /**
     * @param CampaignTrigger[] $triggers
     */
    private function makeTriggerCollection(array $triggers): TriggerCollection
    {
        $collection = $this->createStub(TriggerCollection::class);
        $collection->method('addTriggerEventsFilter')->willReturnSelf();
        $collection->method('addEnabledCampaignFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
    }

    private function makeTrigger(
        int $entityId,
        int $campaignId,
        string $triggerEvent,
        string $paramsJson
    ): CampaignTrigger {
        $trigger = $this->createStub(CampaignTrigger::class);
        $trigger->method('getEntityId')->willReturn($entityId);
        $trigger->method('getCampaignId')->willReturn($campaignId);
        $trigger->method('getTriggerEvent')->willReturn($triggerEvent);
        $trigger->method('getParamsJson')->willReturn($paramsJson);
        $trigger->method('getParams')->willReturn(json_decode($paramsJson, true));

        return $trigger;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testScheduledAtFiresOnceWhenTimeHasPassedAndNeverFiredBefore(): void
    {
        $trigger = $this->makeTrigger(
            1,
            5,
            CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
            '{"scheduled_at": "2026-01-01 00:00:00"}'
        );
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        $this->scheduledTriggerState->method('getState')->willReturn(null);

        $this->campaignDispatcher->expects(self::once())->method('dispatchScheduledTrigger')
            ->with(5, self::callback(fn (array $context) => $context['trigger_event'] === 'scheduled_at'));
        $this->scheduledTriggerState->expects(self::once())->method('markFired')
            ->with(
                5,
                'scheduled_at',
                self::callback(fn ($v) => is_string($v)),
                self::callback(fn ($v) => is_string($v))
            );

        self::assertSame(1, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testScheduledAtDoesNotFireWhenScheduledAtIsMissing(): void
    {
        $trigger = $this->makeTrigger(
            1,
            5,
            CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
            '{}'
        );
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        $this->scheduledTriggerState->method('getState')->willReturn(null);

        $this->campaignDispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        self::assertSame(0, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testScheduledAtDoesNotFireAgainOnceAlreadyFiredUnderTheSameConfig(): void
    {
        $paramsJson = '{"scheduled_at": "2026-01-01 00:00:00"}';
        $trigger = $this->makeTrigger(1, 5, CampaignTriggerInterface::TRIGGER_SCHEDULED_AT, $paramsJson);
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        $this->scheduledTriggerState->method('getState')->willReturn([
            'config_hash' => hash('sha256', $paramsJson),
            'last_fired_at' => '2026-01-01 00:05:00',
        ]);

        $this->campaignDispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        self::assertSame(0, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testScheduledAtFiresAgainWhenTheDateWasChangedSinceItLastFired(): void
    {
        $oldParamsJson = '{"scheduled_at": "2026-01-01 00:00:00"}';
        $newParamsJson = '{"scheduled_at": "2026-01-01 00:00:00"}'; // same value, different hash below on purpose
        $trigger = $this->makeTrigger(1, 5, CampaignTriggerInterface::TRIGGER_SCHEDULED_AT, $newParamsJson);
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        // A stale hash (as if the date had been different before) proves a config change is
        // treated as not-yet-fired, not the literal string equality of the date itself.
        $this->scheduledTriggerState->method('getState')->willReturn([
            'config_hash' => hash('sha256', '{"scheduled_at": "2025-01-01 00:00:00"}'),
            'last_fired_at' => '2025-06-01 00:00:00',
        ]);

        $this->campaignDispatcher->expects(self::once())->method('dispatchScheduledTrigger');

        self::assertSame(1, $this->makeScanner()->scan());
        self::assertNotEmpty($oldParamsJson);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testScheduledAtDoesNotFireBeforeItsOwnTime(): void
    {
        $trigger = $this->makeTrigger(
            1,
            5,
            CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
            '{"scheduled_at": "2099-01-01 00:00:00"}'
        );
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        $this->scheduledTriggerState->method('getState')->willReturn(null);

        $this->campaignDispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        self::assertSame(0, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecurringScheduleFiresWhenCronExpressionMatchesNow(): void
    {
        $trigger = $this->makeTrigger(
            1,
            5,
            CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE,
            '{"cron_expression": "* * * * *"}'
        );
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        $this->scheduledTriggerState->method('getState')->willReturn(null);

        $cronSchedule = $this->createStub(CronSchedule::class);
        $cronSchedule->method('matchCronExpression')->willReturn(true);
        $this->cronScheduleFactory->method('create')->willReturn($cronSchedule);

        $this->campaignDispatcher->expects(self::once())->method('dispatchScheduledTrigger');

        self::assertSame(1, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecurringScheduleDoesNotFireWhenCronExpressionDoesNotMatch(): void
    {
        $trigger = $this->makeTrigger(
            1,
            5,
            CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE,
            '{"cron_expression": "0 8 * * 1"}'
        );
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        $this->scheduledTriggerState->method('getState')->willReturn(null);

        $cronSchedule = $this->createStub(CronSchedule::class);
        $cronSchedule->method('matchCronExpression')->willReturn(false);
        $this->cronScheduleFactory->method('create')->willReturn($cronSchedule);

        $this->campaignDispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        self::assertSame(0, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecurringScheduleDoesNotFireTwiceWithinTheSameMinute(): void
    {
        $paramsJson = '{"cron_expression": "* * * * *"}';
        $trigger = $this->makeTrigger(1, 5, CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE, $paramsJson);
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:00');
        $this->scheduledTriggerState->method('getState')->willReturn([
            'config_hash' => hash('sha256', $paramsJson),
            'last_fired_at' => $now,
        ]);

        $this->campaignDispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        self::assertSame(0, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMalformedCronExpressionIsTreatedAsNotDueRatherThanThrowing(): void
    {
        $trigger = $this->makeTrigger(
            1,
            5,
            CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE,
            '{"cron_expression": "not a cron expression"}'
        );
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));
        $this->scheduledTriggerState->method('getState')->willReturn(null);

        $this->campaignDispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        self::assertSame(0, $this->makeScanner()->scan());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAFailingTriggerIsLoggedAndDoesNotAbortTheRestOfTheScan(): void
    {
        $goodTrigger = $this->makeTrigger(
            1,
            5,
            CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
            '{"scheduled_at": "2026-01-01 00:00:00"}'
        );
        $badTrigger = $this->makeTrigger(2, 6, CampaignTriggerInterface::TRIGGER_SCHEDULED_AT, '{}');
        $this->triggerCollectionFactory->method('create')->willReturn(
            $this->makeTriggerCollection([$badTrigger, $goodTrigger])
        );
        $this->scheduledTriggerState->method('getState')->willReturnCallback(
            function (int $campaignId) {
                if ($campaignId === 6) {
                    throw new \RuntimeException('DB is down');
                }
                return null;
            }
        );

        $this->logger->expects(self::once())->method('error');
        $this->campaignDispatcher->expects(self::once())->method('dispatchScheduledTrigger')->with(5, self::anything());

        self::assertSame(1, $this->makeScanner()->scan());
    }
}
