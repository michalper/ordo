<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\Calendar;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\TypeLabels;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

/**
 * One place to see every campaign's trigger(s) and its action chain's timing — a campaign's
 * "trigger window" is really an event type, not a wall-clock time (only the reminder/alert
 * crons run on a schedule, and those aren't campaigns), and delay_minutes is the wait AFTER
 * the PREVIOUS action, not from campaign start (see CampaignDispatcher::runActionsFrom()) —
 * so what this actually renders is each action's own delay alongside its CUMULATIVE offset
 * from the trigger firing, since that's what an admin scanning for "when does this customer
 * actually get action #3" wants, not just the raw per-step delay already visible on the edit
 * form's dynamicRows. Pure read of data already modeled (ordo_campaign/_trigger/_action) — no
 * new entities, same server-rendered pattern as Block\Adminhtml\Dashboard\DashboardViewModel.
 */
class CampaignCalendarViewModel implements ArgumentInterface
{
    /**
     * @var array<int, string>|null Trigger labels per campaign_id, built in one query by
     * getCampaigns() so the per-campaign lookup below never re-queries.
     */
    private ?array $triggerLabelsByCampaignId = null;

    /**
     * @var array<int, array<int, array{type: string, label: string, delay_minutes: int, offset_minutes: int}>>|null
     * Action timeline per campaign_id, same one-query-up-front shape as the trigger labels above.
     */
    private ?array $timelineByCampaignId = null;

    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly TriggerEvent $triggerEventSource,
        private readonly TypeLabels $typeLabels
    ) {
    }

    /**
     * @return CampaignInterface[]
     */
    public function getCampaigns(): array
    {
        $collection = $this->campaignCollectionFactory->create();
        $collection->setOrder('name', 'ASC');

        $campaigns = [];
        $campaignIds = [];
        foreach ($collection as $campaign) {
            /** @var Campaign $campaign */
            $campaigns[] = $campaign;
            $campaignIds[] = (int) $campaign->getEntityId();
        }

        $this->triggerLabelsByCampaignId = $this->loadTriggerLabelsByCampaignId($campaignIds);
        $this->timelineByCampaignId = $this->loadTimelineByCampaignId($campaignIds);

        return $campaigns;
    }

    public function getTriggerLabelsForCampaign(int $campaignId): string
    {
        $this->triggerLabelsByCampaignId ??= $this->loadTriggerLabelsByCampaignId([$campaignId]);

        return $this->triggerLabelsByCampaignId[$campaignId] ?? (string) __('No trigger configured');
    }

    /**
     * @return array<int, array{type: string, label: string, delay_minutes: int, offset_minutes: int}>
     */
    public function getActionTimelineForCampaign(int $campaignId): array
    {
        $this->timelineByCampaignId ??= $this->loadTimelineByCampaignId([$campaignId]);

        return $this->timelineByCampaignId[$campaignId] ?? [];
    }

    /**
     * Raw "+1440 min" / "+2880 min" was reported directly as unreadable — a minute count doesn't
     * read as "a day" or "two days" at a glance, which is exactly the scale most delay_minutes
     * values actually sit at (this module's own action-editing UI and campaign templates commonly
     * use 1440/2880-minute delays for "next day"/"two days later" follow-ups). Picks the coarsest
     * unit that divides evenly (days when a whole number of days, else hours when a whole number
     * of hours, else minutes) rather than always showing a combined "1d 2h 3m" breakdown, since
     * these delays are near-universally round numbers a merchant chose in whole days/hours.
     */
    public function formatOffsetMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return (string) __('immediate');
        }

        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);
            return $days === 1 ? (string) __('+1 day') : (string) __('+%1 days', $days);
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);
            return $hours === 1 ? (string) __('+1 hour') : (string) __('+%1 hours', $hours);
        }

        return (string) __('+%1 min', $minutes);
    }

    /**
     * @param int[] $campaignIds
     * @return array<int, string>
     */
    private function loadTriggerLabelsByCampaignId(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $labels = [];
        foreach ($this->triggerEventSource->toOptionArray() as $option) {
            $labels[$option['value']] = (string) $option['label'];
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addFieldToFilter('campaign_id', ['in' => $campaignIds]);

        $labelsByCampaignId = [];
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            $campaignId = $trigger->getCampaignId();
            $event = $trigger->getTriggerEvent();
            $labelsByCampaignId[$campaignId][] = $labels[$event] ?? $event;
        }

        return array_map(
            static fn (array $eventLabels): string => implode(', ', $eventLabels),
            $labelsByCampaignId
        );
    }

    /**
     * One query for every campaign_id in $campaignIds — sort_order gives chain order, the
     * cumulative offset is a running sum of each step's own delay_minutes (its wait after the
     * PREVIOUS step, per CampaignDispatcher::runActionsFrom()), not the campaign's own value.
     *
     * @param int[] $campaignIds
     * @return array<int, array<int, array{type: string, label: string, delay_minutes: int, offset_minutes: int}>>
     */
    private function loadTimelineByCampaignId(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $actions = $this->campaignActionCollectionFactory->create();
        $actions->addCampaignIdsFilter($campaignIds);

        $timelineByCampaignId = [];
        $offsetByCampaignId = [];
        foreach ($actions as $action) {
            /** @var CampaignAction $action */
            $campaignId = $action->getCampaignId();
            $offsetByCampaignId[$campaignId] ??= 0;
            $offsetByCampaignId[$campaignId] += $action->getDelayMinutes();

            $timelineByCampaignId[$campaignId][] = [
                'type' => $action->getType(),
                'label' => $this->typeLabels->actionLabel($action->getType()),
                'delay_minutes' => $action->getDelayMinutes(),
                'offset_minutes' => $offsetByCampaignId[$campaignId],
            ];
        }

        return $timelineByCampaignId;
    }
}
