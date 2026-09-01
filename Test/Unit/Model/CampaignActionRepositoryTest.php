<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignActionSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignActionSearchResultsInterfaceFactory;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignActionRepository;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CampaignActionRepositoryTest extends TestCase
{
    private CampaignActionResource $resource;
    private CampaignActionFactory $actionFactory;
    private CollectionFactory $collectionFactory;
    private CampaignActionSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private CampaignActionRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(CampaignActionResource::class);
        $this->actionFactory = $this->createStub(CampaignActionFactory::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->searchResultsFactory = $this->createStub(CampaignActionSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createStub(CollectionProcessorInterface::class);

        $this->repository = new CampaignActionRepository(
            $this->resource,
            $this->actionFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testSaveReturnsSavedAction(): void
    {
        $action = $this->createStub(CampaignAction::class);
        $this->resource->expects(self::once())->method('save')->with($action);

        self::assertSame($action, $this->repository->save($action));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $action = $this->createStub(CampaignAction::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($action);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdReturnsLoadedAction(): void
    {
        $action = $this->createStub(CampaignAction::class);
        $action->method('getEntityId')->willReturn(5);
        $this->actionFactory->method('create')->willReturn($action);

        self::assertSame($action, $this->repository->getById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdThrowsWhenNotFound(): void
    {
        $action = $this->createStub(CampaignAction::class);
        $action->method('getEntityId')->willReturn(null);
        $this->actionFactory->method('create')->willReturn($action);

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

        $searchResults = $this->createMock(CampaignActionSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $action = $this->createStub(CampaignAction::class);
        $action->method('getEntityId')->willReturn(5);
        $this->actionFactory->method('create')->willReturn($action);
        $this->resource->expects(self::once())->method('delete')->with($action);

        self::assertTrue($this->repository->deleteById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $action = $this->createStub(CampaignAction::class);
        $action->method('getEntityId')->willReturn(null);
        $this->actionFactory->method('create')->willReturn($action);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdWrapsExceptionInCouldNotSaveException(): void
    {
        $action = $this->createStub(CampaignAction::class);
        $action->method('getEntityId')->willReturn(5);
        $this->actionFactory->method('create')->willReturn($action);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->deleteById(5);
    }
}
