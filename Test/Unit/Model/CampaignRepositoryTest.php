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
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory;
use PHPUnit\Framework\TestCase;

class CampaignRepositoryTest extends TestCase
{
    private CampaignResource $resource;
    private CampaignFactory $campaignFactory;
    private CollectionFactory $collectionFactory;
    private CampaignSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private CacheInterface $cache;
    private CampaignRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(CampaignResource::class);
        $this->campaignFactory = $this->createMock(CampaignFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(CampaignSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->repository = new CampaignRepository(
            $this->resource,
            $this->campaignFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
            $this->cache
        );
    }

    public function testSaveReturnsSavedCampaign(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $this->resource->expects(self::once())->method('save')->with($campaign);
        $this->cache->expects(self::once())->method('clean')->with([CampaignDispatcher::CACHE_TAG]);

        self::assertSame($campaign, $this->repository->save($campaign));
    }

    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($campaign);
    }

    public function testGetByIdReturnsLoadedCampaign(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->campaignFactory->method('create')->willReturn($campaign);

        self::assertSame($campaign, $this->repository->getById(5));
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $this->campaignFactory->method('create')->willReturn($campaign);

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

        $searchResults = $this->createMock(CampaignSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteReturnsTrue(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $this->resource->expects(self::once())->method('delete')->with($campaign);
        $this->cache->expects(self::once())->method('clean')->with([CampaignDispatcher::CACHE_TAG]);

        self::assertTrue($this->repository->delete($campaign));
    }

    public function testDeleteWrapsExceptionInCouldNotSaveException(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->delete($campaign);
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->campaignFactory->method('create')->willReturn($campaign);
        $this->resource->expects(self::once())->method('delete')->with($campaign);

        self::assertTrue($this->repository->deleteById(5));
    }

    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }
}
