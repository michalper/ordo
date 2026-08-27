<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\CampaignTriggerRepositoryInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Api\Data\CampaignTriggerSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignTriggerSearchResultsInterfaceFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

class CampaignTriggerRepository implements CampaignTriggerRepositoryInterface
{
    public function __construct(
        private readonly CampaignTriggerResource $campaignTriggerResource,
        private readonly CampaignTriggerFactory $campaignTriggerFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignTriggerSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(CampaignTriggerInterface $trigger): CampaignTriggerInterface
    {
        try {
            $this->campaignTriggerResource->save($trigger);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the campaign trigger: %1', $e->getMessage()), $e);
        }

        return $trigger;
    }

    public function getById(int $entityId): CampaignTriggerInterface
    {
        $trigger = $this->campaignTriggerFactory->create();
        $this->campaignTriggerResource->load($trigger, $entityId);

        if (!$trigger->getEntityId()) {
            throw new NoSuchEntityException(__('Campaign trigger with id "%1" does not exist.', $entityId));
        }

        return $trigger;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): CampaignTriggerSearchResultsInterface
    {
        $collection = $this->campaignTriggerCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function deleteById(int $entityId): bool
    {
        $trigger = $this->getById($entityId);

        try {
            $this->campaignTriggerResource->delete($trigger);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the campaign trigger: %1', $e->getMessage()), $e);
        }

        return true;
    }
}
