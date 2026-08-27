<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\CampaignConditionRepositoryInterface;
use Ordo\Automation\Api\Data\CampaignConditionInterface;
use Ordo\Automation\Api\Data\CampaignConditionSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignConditionSearchResultsInterfaceFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;

class CampaignConditionRepository implements CampaignConditionRepositoryInterface
{
    public function __construct(
        private readonly CampaignConditionResource $campaignConditionResource,
        private readonly CampaignConditionFactory $campaignConditionFactory,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignConditionSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(CampaignConditionInterface $condition): CampaignConditionInterface
    {
        try {
            $this->campaignConditionResource->save($condition);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the campaign condition: %1', $e->getMessage()), $e);
        }

        return $condition;
    }

    public function getById(int $entityId): CampaignConditionInterface
    {
        $condition = $this->campaignConditionFactory->create();
        $this->campaignConditionResource->load($condition, $entityId);

        if (!$condition->getEntityId()) {
            throw new NoSuchEntityException(__('Campaign condition with id "%1" does not exist.', $entityId));
        }

        return $condition;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): CampaignConditionSearchResultsInterface
    {
        $collection = $this->campaignConditionCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function deleteById(int $entityId): bool
    {
        $condition = $this->getById($entityId);

        try {
            $this->campaignConditionResource->delete($condition);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the campaign condition: %1', $e->getMessage()), $e);
        }

        return true;
    }
}
