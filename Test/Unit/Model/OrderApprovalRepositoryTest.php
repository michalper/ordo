<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Ordo\Automation\Api\Data\OrderApprovalSearchResultsInterface;
use Ordo\Automation\Api\Data\OrderApprovalSearchResultsInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\OrderApprovalRepository;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Model\ResourceModel\OrderApproval\Collection;
use Ordo\Automation\Model\ResourceModel\OrderApproval\CollectionFactory;
use PHPUnit\Framework\TestCase;

class OrderApprovalRepositoryTest extends TestCase
{
    private OrderApprovalResource $resource;
    private OrderApprovalFactory $orderApprovalFactory;
    private CollectionFactory $collectionFactory;
    private OrderApprovalSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private OrderApprovalRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(OrderApprovalResource::class);
        $this->orderApprovalFactory = $this->createMock(OrderApprovalFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(OrderApprovalSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);

        $this->repository = new OrderApprovalRepository(
            $this->resource,
            $this->orderApprovalFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testGetByIdReturnsLoadedApproval(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getEntityId')->willReturn(5);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        self::assertSame($approval, $this->repository->getById(5));
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getEntityId')->willReturn(null);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(99);
    }

    public function testGetListBuildsSearchResults(): void
    {
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([]);
        $collection->method('getSize')->willReturn(0);
        $this->collectionFactory->method('create')->willReturn($collection);

        $searchResults = $this->createMock(OrderApprovalSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }
}
