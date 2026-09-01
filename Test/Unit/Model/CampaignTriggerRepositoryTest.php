<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignTriggerSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignTriggerSearchResultsInterfaceFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CampaignTriggerRepository;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory;
use PHPUnit\Framework\TestCase;

class CampaignTriggerRepositoryTest extends TestCase
{
    private CampaignTriggerResource $resource;
    private CampaignTriggerFactory $triggerFactory;
    private CollectionFactory $collectionFactory;
    private CampaignTriggerSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private CacheInterface $cache;
    private CampaignTriggerRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(CampaignTriggerResource::class);
        $this->triggerFactory = $this->createMock(CampaignTriggerFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(CampaignTriggerSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->repository = new CampaignTriggerRepository(
            $this->resource,
            $this->triggerFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
            $this->cache
        );
    }

    public function testSaveReturnsSavedTriggerAndCleansDispatchCache(): void
    {
        $trigger = $this->createMock(CampaignTrigger::class);
        $this->resource->expects(self::once())->method('save')->with($trigger);
        $this->cache->expects(self::once())->method('clean')->with([CampaignDispatcher::CACHE_TAG]);

        self::assertSame($trigger, $this->repository->save($trigger));
    }

    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $trigger = $this->createMock(CampaignTrigger::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));
        $this->cache->expects(self::never())->method('clean');

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($trigger);
    }

    public function testGetByIdReturnsLoadedTrigger(): void
    {
        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->method('getEntityId')->willReturn(5);
        $this->triggerFactory->method('create')->willReturn($trigger);

        self::assertSame($trigger, $this->repository->getById(5));
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->method('getEntityId')->willReturn(null);
        $this->triggerFactory->method('create')->willReturn($trigger);

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

        $searchResults = $this->createMock(CampaignTriggerSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteByIdLoadsThenDeletesAndCleansDispatchCache(): void
    {
        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->method('getEntityId')->willReturn(5);
        $this->triggerFactory->method('create')->willReturn($trigger);
        $this->resource->expects(self::once())->method('delete')->with($trigger);
        $this->cache->expects(self::once())->method('clean')->with([CampaignDispatcher::CACHE_TAG]);

        self::assertTrue($this->repository->deleteById(5));
    }

    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->method('getEntityId')->willReturn(null);
        $this->triggerFactory->method('create')->willReturn($trigger);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }

    public function testDeleteByIdWrapsExceptionInCouldNotSaveException(): void
    {
        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->method('getEntityId')->willReturn(5);
        $this->triggerFactory->method('create')->willReturn($trigger);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));
        $this->cache->expects(self::never())->method('clean');

        $this->expectException(CouldNotSaveException::class);
        $this->repository->deleteById(5);
    }
}
