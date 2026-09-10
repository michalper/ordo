<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\CacheInterface;
use Ordo\Automation\Api\Data\CampaignSearchResultsInterface;
use Ordo\Automation\Api\Data\CampaignSearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignRepository;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as CampaignTriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CampaignRepositoryTest extends TestCase
{
    private CampaignResource $resource;
    private CampaignFactory $campaignFactory;
    private CollectionFactory $collectionFactory;
    private CampaignSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private CacheInterface $cache;
    private CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory;
    private CampaignRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(CampaignResource::class);
        $this->campaignFactory = $this->createStub(CampaignFactory::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->searchResultsFactory = $this->createStub(CampaignSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createStub(CollectionProcessorInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $this->campaignTriggerCollectionFactory->method('create')->willReturn(
            $this->triggerCollectionWithEvents([])
        );

        $this->repository = new CampaignRepository(
            $this->resource,
            $this->campaignFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
            $this->cache,
            $this->campaignTriggerCollectionFactory
        );
    }

    /**
     * @param string[] $triggerEvents
     */
    private function triggerCollectionWithEvents(array $triggerEvents): CampaignTriggerCollection
    {
        $triggers = array_map(function (string $triggerEvent): CampaignTrigger {
            $trigger = $this->createStub(CampaignTrigger::class);
            $trigger->method('getTriggerEvent')->willReturn($triggerEvent);
            return $trigger;
        }, $triggerEvents);

        $collection = $this->createStub(CampaignTriggerCollection::class);
        $collection->method('addCampaignFilter')->willReturn($collection);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
    }

    public function testSaveReturnsSavedCampaign(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->resource->expects(self::once())->method('save')->with($campaign);

        $this->cache->expects(self::never())->method('clean');

        self::assertSame($campaign, $this->repository->save($campaign));
    }

    public function testSaveCleansTagsForOldAndNewTriggerEvents(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);

        $collections = [
            $this->triggerCollectionWithEvents(['order_placed']),
            $this->triggerCollectionWithEvents(['order_placed', 'customer_registered']),
        ];
        $this->campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $this->campaignTriggerCollectionFactory->method('create')->willReturnOnConsecutiveCalls(...$collections);

        $this->repository = new CampaignRepository(
            $this->resource,
            $this->campaignFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
            $this->cache,
            $this->campaignTriggerCollectionFactory
        );
        $this->resource->expects(self::once())->method('save')->with($campaign);

        $this->cache->expects(self::once())->method('clean')->with([
            CampaignDispatcher::CACHE_KEY_PREFIX . 'order_placed',
            CampaignDispatcher::CACHE_KEY_PREFIX . 'customer_registered',
        ]);

        $this->repository->save($campaign);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($campaign);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdReturnsLoadedCampaign(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->campaignFactory->method('create')->willReturn($campaign);

        self::assertSame($campaign, $this->repository->getById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdThrowsWhenNotFound(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $this->campaignFactory->method('create')->willReturn($campaign);

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

        $searchResults = $this->createMock(CampaignSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteReturnsTrue(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->resource->expects(self::once())->method('delete')->with($campaign);
        $this->cache->expects(self::never())->method('clean');

        self::assertTrue($this->repository->delete($campaign));
    }

    public function testDeleteCleansTagsForCampaignsTriggerEvents(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);

        $this->campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $this->campaignTriggerCollectionFactory->method('create')->willReturn(
            $this->triggerCollectionWithEvents(['order_placed'])
        );

        $this->repository = new CampaignRepository(
            $this->resource,
            $this->campaignFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
            $this->cache,
            $this->campaignTriggerCollectionFactory
        );
        $this->resource->expects(self::once())->method('delete')->with($campaign);

        $this->cache->expects(self::once())->method('clean')
            ->with([CampaignDispatcher::CACHE_KEY_PREFIX . 'order_placed']);

        $this->repository->delete($campaign);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteWrapsExceptionInCouldNotSaveException(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->delete($campaign);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->campaignFactory->method('create')->willReturn($campaign);
        $this->resource->expects(self::once())->method('delete')->with($campaign);

        self::assertTrue($this->repository->deleteById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }
}
