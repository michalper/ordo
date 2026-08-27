<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\CampaignActionRepositoryInterface;
use Ordo\Automation\Api\Data\CampaignActionInterface;
use Ordo\Automation\Api\Data\CampaignActionSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignActionSearchResultsInterfaceFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;

class CampaignActionRepository implements CampaignActionRepositoryInterface
{
    public function __construct(
        private readonly CampaignActionResource $campaignActionResource,
        private readonly CampaignActionFactory $campaignActionFactory,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly CampaignActionSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(CampaignActionInterface $action): CampaignActionInterface
    {
        try {
            $this->campaignActionResource->save($action);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the campaign action: %1', $e->getMessage()), $e);
        }

        return $action;
    }

    public function getById(int $entityId): CampaignActionInterface
    {
        $action = $this->campaignActionFactory->create();
        $this->campaignActionResource->load($action, $entityId);

        if (!$action->getEntityId()) {
            throw new NoSuchEntityException(__('Campaign action with id "%1" does not exist.', $entityId));
        }

        return $action;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): CampaignActionSearchResultsInterface
    {
        $collection = $this->campaignActionCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function deleteById(int $entityId): bool
    {
        $action = $this->getById($entityId);

        try {
            $this->campaignActionResource->delete($action);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the campaign action: %1', $e->getMessage()), $e);
        }

        return true;
    }
}
