<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\Trigger;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignTrigger as CampaignTriggerModel;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignTriggerModel::class, CampaignTriggerResource::class);
    }

    public function addCampaignFilter(int $campaignId): self
    {
        $this->addFieldToFilter('campaign_id', $campaignId);
        return $this;
    }

    /**
     * Every trigger row for the given event, across all campaigns — the first half of
     * CampaignDispatcher::dispatch()'s lookup: "which campaign_ids even have this trigger at
     * all", before filtering those campaigns down to the enabled ones.
     */
    public function addTriggerEventFilter(string $triggerEvent): self
    {
        $this->addFieldToFilter('trigger_event', $triggerEvent);
        return $this;
    }

    /**
     * Qualified with the table alias (unlike addTriggerEventFilter() above) because
     * addEnabledCampaignFilter() joins ordo_campaign, which has its own (deprecated, unread)
     * trigger_event column - an unqualified filter is ambiguous the moment both are used
     * together, confirmed via a real "Column 'trigger_event' in where clause is ambiguous"
     * SQL error.
     *
     * @param string[] $triggerEvents
     */
    public function addTriggerEventsFilter(array $triggerEvents): self
    {
        $this->addFieldToFilter('main_table.trigger_event', ['in' => $triggerEvents]);
        return $this;
    }

    /**
     * Scoped to enabled campaigns only — Model\Campaign\ScheduledTriggerScanner's own
     * equivalent of CampaignDispatcher::campaignIdsForTrigger()'s enabled check, done as a join
     * instead of a second query since the scanner already needs the full row (not just an id
     * list) to read each trigger's own params.
     */
    public function addEnabledCampaignFilter(): self
    {
        $this->getSelect()->join(
            ['ordo_campaign_enabled_filter' => $this->getTable('ordo_campaign')],
            'main_table.campaign_id = ordo_campaign_enabled_filter.entity_id'
            . ' AND ordo_campaign_enabled_filter.enabled = 1',
            []
        );

        return $this;
    }
}
