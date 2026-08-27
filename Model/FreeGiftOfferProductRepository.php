<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferProductInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferProductSearchResultsInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferProductSearchResultsInterfaceFactory;
use Ordo\Automation\Api\FreeGiftOfferProductRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct as FreeGiftOfferProductResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory as FreeGiftOfferProductCollectionFactory;

class FreeGiftOfferProductRepository implements FreeGiftOfferProductRepositoryInterface
{
    public function __construct(
        private readonly FreeGiftOfferProductResource $productResource,
        private readonly FreeGiftOfferProductFactory $productFactory,
        private readonly FreeGiftOfferProductCollectionFactory $productCollectionFactory,
        private readonly FreeGiftOfferProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(FreeGiftOfferProductInterface $product): FreeGiftOfferProductInterface
    {
        try {
            $this->productResource->save($product);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the free gift product: %1', $e->getMessage()), $e);
        }

        return $product;
    }

    public function getById(int $entityId): FreeGiftOfferProductInterface
    {
        $product = $this->productFactory->create();
        $this->productResource->load($product, $entityId);

        if (!$product->getEntityId()) {
            throw new NoSuchEntityException(__('Free gift product with id "%1" does not exist.', $entityId));
        }

        return $product;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): FreeGiftOfferProductSearchResultsInterface
    {
        $collection = $this->productCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(FreeGiftOfferProductInterface $product): bool
    {
        try {
            $this->productResource->delete($product);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not delete the free gift product: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function deleteById(int $entityId): bool
    {
        return $this->delete($this->getById($entityId));
    }
}
