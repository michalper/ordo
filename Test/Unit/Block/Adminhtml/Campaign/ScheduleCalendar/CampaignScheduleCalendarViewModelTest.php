<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign\ScheduleCalendar;

use Magento\Cron\Model\Schedule as CronSchedule;
use Magento\Cron\Model\ScheduleFactory as CronScheduleFactory;
use Magento\Framework\App\RequestInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Block\Adminhtml\Campaign\ScheduleCalendar\CampaignScheduleCalendarViewModel;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\ScheduledTriggerScanner;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledTriggerState;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as CampaignTriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignScheduleCalendarViewModelTest extends TestCase
{
    private RequestInterface $request;
    private CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory;
    private CampaignCollectionFactory $campaignCollectionFactory;
    private CronScheduleFactory $cronScheduleFactory;

    protected function setUp(): void
    {
        $this->request = $this->createStub(RequestInterface::class);
        $this->campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $this->campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $this->cronScheduleFactory = $this->createStub(CronScheduleFactory::class);
    }

    private function makeViewModel(): CampaignScheduleCalendarViewModel
    {
        $scanner = new ScheduledTriggerScanner(
            $this->campaignTriggerCollectionFactory,
            $this->createStub(ScheduledTriggerState::class),
            $this->createStub(CampaignDispatcher::class),
            $this->cronScheduleFactory,
            $this->createStub(LoggerInterface::class)
        );

        return new CampaignScheduleCalendarViewModel(
            $this->request,
            $this->campaignTriggerCollectionFactory,
            $this->campaignCollectionFactory,
            $scanner
        );
    }

    private function makeTriggerCollection(array $triggers): CampaignTriggerCollection
    {
        $collection = $this->createStub(CampaignTriggerCollection::class);
        $collection->method('addTriggerEventsFilter')->willReturnSelf();
        $collection->method('addEnabledCampaignFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
    }

    private function makeTrigger(int $campaignId, string $triggerEvent, string $paramsJson): CampaignTrigger
    {
        $trigger = $this->createStub(CampaignTrigger::class);
        $trigger->method('getCampaignId')->willReturn($campaignId);
        $trigger->method('getTriggerEvent')->willReturn($triggerEvent);
        $trigger->method('getParams')->willReturn(json_decode($paramsJson, true));

        return $trigger;
    }

    private function makeCampaignCollection(array $campaigns): CampaignCollection
    {
        $collection = $this->createStub(CampaignCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($campaigns));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetVisibleMonthStartDefaultsToCurrentMonthWhenNoParamGiven(): void
    {
        $this->request->method('getParam')->willReturn('');

        $viewModel = $this->makeViewModel();
        $expected = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));

        self::assertSame($expected->format('Y-m'), $viewModel->getVisibleMonthStart()->format('Y-m'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetVisibleMonthStartParsesTheMonthParam(): void
    {
        $this->request->method('getParam')->willReturn('2026-11');

        self::assertSame('2026-11-01', $this->makeViewModel()->getVisibleMonthStart()->format('Y-m-d'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrevAndNextMonthParamsStepByOneMonth(): void
    {
        $this->request->method('getParam')->willReturn('2026-11');

        $viewModel = $this->makeViewModel();
        self::assertSame('2026-10', $viewModel->getPrevMonthParam());
        self::assertSame('2026-12', $viewModel->getNextMonthParam());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCalendarDaysPlotsAScheduledAtTriggerOnItsOwnDay(): void
    {
        $this->request->method('getParam')->willReturn('2026-11');

        $trigger = $this->makeTrigger(
            5,
            CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
            '{"scheduled_at": "2026-11-15 09:00:00"}'
        );
        $this->campaignTriggerCollectionFactory->method('create')
            ->willReturn($this->makeTriggerCollection([$trigger]));

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $campaign->method('getName')->willReturn('Black Friday blast');
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        $days = $this->makeViewModel()->getCalendarDays();

        $matching = array_values(array_filter($days, static fn (array $day): bool => $day['date']->format('Y-m-d') === '2026-11-15'));
        self::assertCount(1, $matching);
        self::assertCount(1, $matching[0]['entries']);
        self::assertSame('Black Friday blast', $matching[0]['entries'][0]['campaign_name']);
        self::assertSame(CampaignTriggerInterface::TRIGGER_SCHEDULED_AT, $matching[0]['entries'][0]['trigger_event']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCalendarDaysExpandsARecurringScheduleAcrossTheVisibleMonth(): void
    {
        $this->request->method('getParam')->willReturn('2026-11');

        $trigger = $this->makeTrigger(
            7,
            CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE,
            '{"cron_expression": "0 8 1 * *"}'
        );
        $this->campaignTriggerCollectionFactory->method('create')
            ->willReturn($this->makeTriggerCollection([$trigger]));

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $campaign->method('getName')->willReturn('Monthly newsletter');
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        // "1 * *" (day-of-month 1, every month, every weekday) - matchCronExpression() for the
        // day field only returns true on the 1st.
        $cronSchedule = $this->createStub(CronSchedule::class);
        $cronSchedule->method('matchCronExpression')->willReturnCallback(
            static fn (string $expr, int $value): bool => $expr !== '1' || $value === 1
        );
        $this->cronScheduleFactory->method('create')->willReturn($cronSchedule);

        $days = $this->makeViewModel()->getCalendarDays();

        // The grid also renders a few leading/trailing days from adjacent months to keep every
        // week row complete (see getCalendarDays()), so December 1st (also a "day 1") can appear
        // too - only November 1st matters for "expands across the visible month".
        $daysWithEntries = array_values(array_filter(
            $days,
            static fn (array $day): bool => !empty($day['entries']) && $day['inCurrentMonth']
        ));
        self::assertCount(1, $daysWithEntries);
        self::assertSame('2026-11-01', $daysWithEntries[0]['date']->format('Y-m-d'));
        self::assertSame('Monthly newsletter', $daysWithEntries[0]['entries'][0]['campaign_name']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCalendarDaysMarksPastEntriesDifferentlyFromUpcomingOnes(): void
    {
        $pastDate = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->request->method('getParam')->willReturn((new \DateTimeImmutable('now'))->format('Y-m'));

        $trigger = $this->makeTrigger(
            9,
            CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
            json_encode(['scheduled_at' => $pastDate])
        );
        $this->campaignTriggerCollectionFactory->method('create')
            ->willReturn($this->makeTriggerCollection([$trigger]));

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(9);
        $campaign->method('getName')->willReturn('Already fired');
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        $days = $this->makeViewModel()->getCalendarDays();

        $matching = array_values(array_filter($days, static fn (array $day): bool => !empty($day['entries'])));
        self::assertNotEmpty($matching);
        self::assertSame('past', $matching[0]['entries'][0]['status']);
    }
}
