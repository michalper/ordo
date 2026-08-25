<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;

class CampaignRepository implements CampaignRepositoryInterface
{
    public function __construct(
        private readonly CampaignResource $campaignResource,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(CampaignInterface $campaign): CampaignInterface
    {
        try {
            $this->campaignResource->save($campaign);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the campaign: %1', $e->getMessage()), $e);
        }

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

    public function getList(SearchCriteriaInterface $searchCriteria)
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
        try {
            $this->campaignResource->delete($campaign);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the campaign: %1', $e->getMessage()), $e);
        }

        return true;
    }
}
