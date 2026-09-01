<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignConditionSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignConditionSearchResultsInterfaceFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignConditionRepository;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CampaignConditionRepositoryTest extends TestCase
{
    private CampaignConditionResource $resource;
    private CampaignConditionFactory $conditionFactory;
    private CollectionFactory $collectionFactory;
    private CampaignConditionSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private CampaignConditionRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(CampaignConditionResource::class);
        $this->conditionFactory = $this->createStub(CampaignConditionFactory::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->searchResultsFactory = $this->createStub(CampaignConditionSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createStub(CollectionProcessorInterface::class);

        $this->repository = new CampaignConditionRepository(
            $this->resource,
            $this->conditionFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testSaveReturnsSavedCondition(): void
    {
        $condition = $this->createStub(CampaignCondition::class);
        $this->resource->expects(self::once())->method('save')->with($condition);

        self::assertSame($condition, $this->repository->save($condition));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $condition = $this->createStub(CampaignCondition::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($condition);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdReturnsLoadedCondition(): void
    {
        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getEntityId')->willReturn(5);
        $this->conditionFactory->method('create')->willReturn($condition);

        self::assertSame($condition, $this->repository->getById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdThrowsWhenNotFound(): void
    {
        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getEntityId')->willReturn(null);
        $this->conditionFactory->method('create')->willReturn($condition);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetListBuildsSearchResults(): void
    {
        $criteria = $this->createStub(SearchCriteriaInterface::class);
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([]);
        $collection->method('getSize')->willReturn(0);
        $this->collectionFactory->method('create')->willReturn($collection);

        $searchResults = $this->createMock(CampaignConditionSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getEntityId')->willReturn(5);
        $this->conditionFactory->method('create')->willReturn($condition);
        $this->resource->expects(self::once())->method('delete')->with($condition);

        self::assertTrue($this->repository->deleteById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getEntityId')->willReturn(null);
        $this->conditionFactory->method('create')->willReturn($condition);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdWrapsExceptionInCouldNotSaveException(): void
    {
        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getEntityId')->willReturn(5);
        $this->conditionFactory->method('create')->willReturn($condition);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->deleteById(5);
    }
}
