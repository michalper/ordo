<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferTierInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferTierSearchResultsInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferTierSearchResultsInterfaceFactory;
use Ordo\Automation\Api\FreeGiftOfferTierRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier as FreeGiftOfferTierResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory as FreeGiftOfferTierCollectionFactory;

class FreeGiftOfferTierRepository implements FreeGiftOfferTierRepositoryInterface
{
    public function __construct(
        private readonly FreeGiftOfferTierResource $tierResource,
        private readonly FreeGiftOfferTierFactory $tierFactory,
        private readonly FreeGiftOfferTierCollectionFactory $tierCollectionFactory,
        private readonly FreeGiftOfferTierSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(FreeGiftOfferTierInterface $tier): FreeGiftOfferTierInterface
    {
        try {
            $this->tierResource->save($tier);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the free gift tier: %1', $e->getMessage()), $e);
        }

        return $tier;
    }

    public function getById(int $entityId): FreeGiftOfferTierInterface
    {
        $tier = $this->tierFactory->create();
        $this->tierResource->load($tier, $entityId);

        if (!$tier->getEntityId()) {
            throw new NoSuchEntityException(__('Free gift tier with id "%1" does not exist.', $entityId));
        }

        return $tier;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): FreeGiftOfferTierSearchResultsInterface
    {
        $collection = $this->tierCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(FreeGiftOfferTierInterface $tier): bool
    {
        try {
            $this->tierResource->delete($tier);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the free gift tier: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function deleteById(int $entityId): bool
    {
        return $this->delete($this->getById($entityId));
    }
}
