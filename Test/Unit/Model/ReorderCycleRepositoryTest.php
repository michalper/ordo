<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Ordo\Automation\Api\Data\ReorderCycleSearchResultsInterface;
use Ordo\Automation\Api\Data\ReorderCycleSearchResultsInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ReorderCycleRepository;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\Collection;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory;
use PHPUnit\Framework\TestCase;

class ReorderCycleRepositoryTest extends TestCase
{
    private ReorderCycleResource $resource;
    private ReorderCycleFactory $reorderCycleFactory;
    private CollectionFactory $collectionFactory;
    private ReorderCycleSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private ReorderCycleRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ReorderCycleResource::class);
        $this->reorderCycleFactory = $this->createMock(ReorderCycleFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(ReorderCycleSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);

        $this->repository = new ReorderCycleRepository(
            $this->resource,
            $this->reorderCycleFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testGetByIdReturnsLoadedReorderCycle(): void
    {
        $reorderCycle = $this->createMock(ReorderCycle::class);
        $reorderCycle->method('getEntityId')->willReturn(5);
        $this->reorderCycleFactory->method('create')->willReturn($reorderCycle);

        self::assertSame($reorderCycle, $this->repository->getById(5));
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $reorderCycle = $this->createMock(ReorderCycle::class);
        $reorderCycle->method('getEntityId')->willReturn(null);
        $this->reorderCycleFactory->method('create')->willReturn($reorderCycle);

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

        $searchResults = $this->createMock(ReorderCycleSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }
}
