<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\ReorderCycleInterface;
use Ordo\Automation\Api\Data\ReorderCycleSearchResultsInterface;
use Ordo\Automation\Api\Data\ReorderCycleSearchResultsInterfaceFactory;
use Ordo\Automation\Api\ReorderCycleRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;

class ReorderCycleRepository implements ReorderCycleRepositoryInterface
{
    public function __construct(
        private readonly ReorderCycleResource $reorderCycleResource,
        private readonly ReorderCycleFactory $reorderCycleFactory,
        private readonly ReorderCycleCollectionFactory $reorderCycleCollectionFactory,
        private readonly ReorderCycleSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function getById(int $entityId): ReorderCycleInterface
    {
        $reorderCycle = $this->reorderCycleFactory->create();
        $this->reorderCycleResource->load($reorderCycle, $entityId);

        if (!$reorderCycle->getEntityId()) {
            throw new NoSuchEntityException(__('Reorder cycle with id "%1" does not exist.', $entityId));
        }

        return $reorderCycle;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): ReorderCycleSearchResultsInterface
    {
        $collection = $this->reorderCycleCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }
}
