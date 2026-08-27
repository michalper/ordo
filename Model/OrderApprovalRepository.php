<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OrderApprovalInterface;
use Ordo\Automation\Api\OrderApprovalRepositoryInterface;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Model\ResourceModel\OrderApproval\CollectionFactory as OrderApprovalCollectionFactory;

class OrderApprovalRepository implements OrderApprovalRepositoryInterface
{
    public function __construct(
        private readonly OrderApprovalResource $orderApprovalResource,
        private readonly OrderApprovalFactory $orderApprovalFactory,
        private readonly OrderApprovalCollectionFactory $orderApprovalCollectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function getById(int $entityId): OrderApprovalInterface
    {
        $orderApproval = $this->orderApprovalFactory->create();
        $this->orderApprovalResource->load($orderApproval, $entityId);

        if (!$orderApproval->getEntityId()) {
            throw new NoSuchEntityException(__('Order approval with id "%1" does not exist.', $entityId));
        }

        return $orderApproval;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->orderApprovalCollectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }
}
