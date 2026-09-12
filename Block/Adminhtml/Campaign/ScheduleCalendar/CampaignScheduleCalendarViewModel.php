<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\ScheduleCalendar;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\ScheduledTriggerScanner;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

/**
 * Date-grid calendar for `scheduled_at`/`recurring_schedule` campaign triggers — the actual
 * "when does this fire on a wall-clock date" view ROADMAP.md's "Scheduled (date-based) campaigns:
 * calendar view" item asked for, deliberately separate from
 * Block\Adminhtml\Campaign\Calendar\CampaignCalendarViewModel ("Campaign Action Timeline"): that
 * page was explicitly renamed away from calendar/date framing (see its own controller's comment)
 * because most campaigns fire on customer EVENTS, not fixed dates, so a real date grid would be
 * empty/misleading for them. Only scheduled_at/recurring_schedule triggers have actual fire
 * dates, so this page reads just those, reusing Model\Campaign\ScheduledTriggerScanner's own
 * due-ness/recurrence-matching logic (matchesCronExpressionDate()) rather than re-implementing
 * cron-expression matching here.
 */
class CampaignScheduleCalendarViewModel implements ArgumentInterface
{
    private const array SCHEDULED_TRIGGER_EVENTS = [
        CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
        CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE,
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly ScheduledTriggerScanner $scheduledTriggerScanner
    ) {
    }

    /**
     * The first day of the visible month — the "month" GET param (format "Y-m"), defaulting to
     * the current month when absent/unparseable.
     */
    public function getVisibleMonthStart(): \DateTimeImmutable
    {
        $month = (string) $this->request->getParam('month', '');
        $parsed = $month !== ''
            ? \DateTimeImmutable::createFromFormat('!Y-m', $month, new \DateTimeZone('UTC'))
            : false;

        return $parsed instanceof \DateTimeImmutable
            ? $parsed
            : new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
    }

    public function getPrevMonthParam(): string
    {
        return $this->getVisibleMonthStart()->modify('-1 month')->format('Y-m');
    }

    public function getNextMonthParam(): string
    {
        return $this->getVisibleMonthStart()->modify('+1 month')->format('Y-m');
    }

    public function getVisibleMonthLabel(): string
    {
        return $this->getVisibleMonthStart()->format('F Y');
    }

    /**
     * One entry per day of a full calendar grid (always starting on a Sunday, ending on a
     * Saturday, so every week row is complete) covering the visible month.
     *
     * @return array<int, array{
     *     date: \DateTimeImmutable,
     *     inCurrentMonth: bool,
     *     isToday: bool,
     *     entries: array<int, array{campaign_id: int, campaign_name: string, trigger_event: string, status: string}>
     * }>
     */
    public function getCalendarDays(): array
    {
        $monthStart = $this->getVisibleMonthStart();
        $monthEnd = $monthStart->modify('last day of this month');
        $gridStart = $monthStart->modify('-' . (int) $monthStart->format('w') . ' days');
        $gridEnd = $monthEnd->modify('+' . (6 - (int) $monthEnd->format('w')) . ' days');

        $entriesByDate = $this->loadEntriesByDate($gridStart, $gridEnd);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        $days = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $dateKey = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $cursor,
                'inCurrentMonth' => $cursor >= $monthStart && $cursor <= $monthEnd,
                'isToday' => $dateKey === $today->format('Y-m-d'),
                'entries' => $entriesByDate[$dateKey] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * @return array<string, array<int, array{campaign_id: int, campaign_name: string, trigger_event: string, status: string}>>
     *  keyed "Y-m-d"
     */
    private function loadEntriesByDate(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventsFilter(self::SCHEDULED_TRIGGER_EVENTS);
        $triggers->addEnabledCampaignFilter();

        $campaignIds = [];
        $triggerList = [];
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            $campaignIds[] = $trigger->getCampaignId();
            $triggerList[] = $trigger;
        }

        $campaignNamesById = $this->loadCampaignNamesById($campaignIds);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        $entriesByDate = [];
        foreach ($triggerList as $trigger) {
            /** @var CampaignTrigger $trigger */
            $campaignId = $trigger->getCampaignId();
            $campaignName = $campaignNamesById[$campaignId] ?? (string) __('(deleted campaign)');

            foreach ($this->fireDatesInRange($trigger, $rangeStart, $rangeEnd) as $fireDate) {
                $entriesByDate[$fireDate->format('Y-m-d')][] = [
                    'campaign_id' => $campaignId,
                    'campaign_name' => $campaignName,
                    'trigger_event' => $trigger->getTriggerEvent(),
                    'status' => $fireDate < $today ? 'past' : 'upcoming',
                ];
            }
        }

        return $entriesByDate;
    }

    /**
     * @return \DateTimeImmutable[] Every day within [$rangeStart, $rangeEnd] this trigger fires
     *  on — at most one for scheduled_at, zero or more for recurring_schedule.
     */
    private function fireDatesInRange(
        CampaignTrigger $trigger,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd
    ): array {
        if ($trigger->getTriggerEvent() === CampaignTriggerInterface::TRIGGER_SCHEDULED_AT) {
            $scheduledAt = $this->parseDate((string) ($trigger->getParams()['scheduled_at'] ?? ''));
            if (!$scheduledAt instanceof \DateTimeImmutable || $scheduledAt < $rangeStart || $scheduledAt > $rangeEnd) {
                return [];
            }

            return [$scheduledAt];
        }

        $cronExpression = (string) ($trigger->getParams()['cron_expression'] ?? '');
        if ($cronExpression === '') {
            return [];
        }

        $dates = [];
        $cursor = $rangeStart;
        while ($cursor <= $rangeEnd) {
            if ($this->scheduledTriggerScanner->matchesCronExpressionDate($cronExpression, $cursor)) {
                $dates[] = $cursor;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * @param int[] $campaignIds
     * @return array<int, string>
     */
    private function loadCampaignNamesById(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $collection = $this->campaignCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => array_unique($campaignIds)]);

        $namesById = [];
        foreach ($collection as $campaign) {
            /** @var Campaign $campaign */
            $namesById[(int) $campaign->getEntityId()] = $campaign->getName();
        }

        return $namesById;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            return null;
        }

        return $parsed->setTime(0, 0);
    }
}
