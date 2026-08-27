<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferSearchResultsInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferSearchResultsInterfaceFactory;
use Ordo\Automation\Api\FreeGiftOfferRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;

class FreeGiftOfferRepository implements FreeGiftOfferRepositoryInterface
{
    public function __construct(
        private readonly FreeGiftOfferResource $offerResource,
        private readonly FreeGiftOfferFactory $offerFactory,
        private readonly FreeGiftOfferCollectionFactory $offerCollectionFactory,
        private readonly FreeGiftOfferSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(FreeGiftOfferInterface $offer): FreeGiftOfferInterface
    {
        try {
            $this->offerResource->save($offer);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the free gift offer: %1', $e->getMessage()), $e);
        }

        return $offer;
    }

    public function getById(int $entityId): FreeGiftOfferInterface
    {
        $offer = $this->offerFactory->create();
        $this->offerResource->load($offer, $entityId);

        if (!$offer->getEntityId()) {
            throw new NoSuchEntityException(__('Free gift offer with id "%1" does not exist.', $entityId));
        }

        return $offer;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): FreeGiftOfferSearchResultsInterface
    {
        $collection = $this->offerCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(FreeGiftOfferInterface $offer): bool
    {
        try {
            $this->offerResource->delete($offer);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the free gift offer: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function deleteById(int $entityId): bool
    {
        return $this->delete($this->getById($entityId));
    }
}
