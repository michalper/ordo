<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\OfferRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;
use Ordo\Automation\Model\ResourceModel\Offer\CollectionFactory as OfferCollectionFactory;

class OfferRepository implements OfferRepositoryInterface
{
    public function __construct(
        private readonly OfferResource $offerResource,
        private readonly OfferFactory $offerFactory,
        private readonly OfferCollectionFactory $offerCollectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(OfferInterface $offer): OfferInterface
    {
        try {
            $this->offerResource->save($offer);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the offer: %1', $e->getMessage()), $e);
        }

        return $offer;
    }

    public function getById(int $entityId): OfferInterface
    {
        $offer = $this->offerFactory->create();
        $this->offerResource->load($offer, $entityId);

        if (!$offer->getEntityId()) {
            throw new NoSuchEntityException(__('Offer with id "%1" does not exist.', $entityId));
        }

        return $offer;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->offerCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(OfferInterface $offer): bool
    {
        try {
            $this->offerResource->delete($offer);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the offer: %1', $e->getMessage()), $e);
        }

        return true;
    }
}
