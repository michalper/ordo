<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Api\Data\CampaignSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignSearchResultsInterfaceFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

class CampaignRepository implements CampaignRepositoryInterface
{
    public function __construct(
        private readonly CampaignResource $campaignResource,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly CacheInterface $cache,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory
    ) {
    }

    public function save(CampaignInterface $campaign): CampaignInterface
    {
        // Read BEFORE save: once campaignResource->save() runs, the campaign's own
        // ordo_campaign_trigger rows may already reflect the NEW associations (they're saved by
        // the same request, via CampaignTriggerRepository, typically just before this call) —
        // this is our only chance to see which trigger events it used to be tied to.
        $entityId = $campaign->getEntityId();
        $oldTriggerEvents = $entityId !== null ? $this->triggerEventsForCampaign($entityId) : [];

        try {
            $this->campaignResource->save($campaign);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the campaign: %1', $e->getMessage()), $e);
        }

        // A save can change enabled status, or (via its child triggers/conditions/actions) which
        // trigger events fire it — CampaignDispatcher's cached "campaign ids for trigger event"
        // lookups must not keep serving a now-stale answer. Only the trigger events this campaign
        // was, or now is, associated with can possibly be affected, so flush exactly those two
        // sets' cache tags (a trigger event added AND removed in the same save is covered by the
        // union) rather than every trigger event's cache entry.
        $newTriggerEvents = $this->triggerEventsForCampaign($campaign->getEntityId());
        $this->cleanTriggerCacheTags($oldTriggerEvents, $newTriggerEvents);

        return $campaign;
    }

    public function getById(int $entityId): CampaignInterface
    {
        $campaign = $this->campaignFactory->create();
        $this->campaignResource->load($campaign, $entityId);

        if (!$campaign->getEntityId()) {
            throw new NoSuchEntityException(__('Campaign with id "%1" does not exist.', $entityId));
        }

        return $campaign;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): CampaignSearchResultsInterface
    {
        $collection = $this->campaignCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(CampaignInterface $campaign): bool
    {
        // Read BEFORE delete: once the campaign row (and, via cascading FKs, its
        // ordo_campaign_trigger rows) is gone, there's nothing left to read its trigger events
        // from — this is the only chance to see what needs invalidating.
        $triggerEvents = $this->triggerEventsForCampaign($campaign->getEntityId());

        try {
            $this->campaignResource->delete($campaign);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the campaign: %1', $e->getMessage()), $e);
        }

        $this->cleanTriggerCacheTags($triggerEvents, []);

        return true;
    }

    public function deleteById(int $entityId): bool
    {
        return $this->delete($this->getById($entityId));
    }

    /**
     * @return string[] distinct trigger_event values this campaign currently has a row for
     */
    private function triggerEventsForCampaign(?int $campaignId): array
    {
        if ($campaignId === null) {
            return [];
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addCampaignFilter($campaignId);

        $triggerEvents = [];
        /** @var CampaignTrigger $trigger */
        foreach ($triggers as $trigger) {
            $triggerEvents[$trigger->getTriggerEvent()] = true;
        }

        return array_keys($triggerEvents);
    }

    /**
     * Flushes CampaignDispatcher's campaignIdsForTrigger() cache entry for every trigger event in
     * either set — the union, not just the new set, so a trigger event removed from a campaign
     * (present only in $oldTriggerEvents) still gets its stale "this campaign is enabled for it"
     * entry cleared.
     *
     * @param string[] $oldTriggerEvents
     * @param string[] $newTriggerEvents
     */
    private function cleanTriggerCacheTags(array $oldTriggerEvents, array $newTriggerEvents): void
    {
        $triggerEvents = array_values(array_unique([...$oldTriggerEvents, ...$newTriggerEvents]));

        if ($triggerEvents === []) {
            return;
        }

        $tags = array_map(
            static fn (string $triggerEvent): string => CampaignDispatcher::CACHE_KEY_PREFIX . $triggerEvent,
            $triggerEvents
        );
        $this->cache->clean($tags);
    }
}
