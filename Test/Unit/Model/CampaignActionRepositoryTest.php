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
        $this->actionFactory = $this->createMock(CampaignActionFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(CampaignActionSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);

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
        $action = $this->createMock(CampaignAction::class);
        $this->resource->expects(self::once())->method('save')->with($action);

        self::assertSame($action, $this->repository->save($action));
    }

    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $action = $this->createMock(CampaignAction::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($action);
    }

    public function testGetByIdReturnsLoadedAction(): void
    {
        $action = $this->createMock(CampaignAction::class);
        $action->method('getEntityId')->willReturn(5);
        $this->actionFactory->method('create')->willReturn($action);

        self::assertSame($action, $this->repository->getById(5));
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $action = $this->createMock(CampaignAction::class);
        $action->method('getEntityId')->willReturn(null);
        $this->actionFactory->method('create')->willReturn($action);

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

        $searchResults = $this->createMock(CampaignActionSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $action = $this->createMock(CampaignAction::class);
        $action->method('getEntityId')->willReturn(5);
        $this->actionFactory->method('create')->willReturn($action);
        $this->resource->expects(self::once())->method('delete')->with($action);

        self::assertTrue($this->repository->deleteById(5));
    }

    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $action = $this->createMock(CampaignAction::class);
        $action->method('getEntityId')->willReturn(null);
        $this->actionFactory->method('create')->willReturn($action);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }

    public function testDeleteByIdWrapsExceptionInCouldNotSaveException(): void
    {
        $action = $this->createMock(CampaignAction::class);
        $action->method('getEntityId')->willReturn(5);
        $this->actionFactory->method('create')->willReturn($action);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->deleteById(5);
    }
}
