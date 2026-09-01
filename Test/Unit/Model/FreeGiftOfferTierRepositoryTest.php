<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferTierSearchResultsInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferTierSearchResultsInterfaceFactory;
use Ordo\Automation\Model\FreeGiftOfferTier;
use Ordo\Automation\Model\FreeGiftOfferTierFactory;
use Ordo\Automation\Model\FreeGiftOfferTierRepository;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier as FreeGiftOfferTierResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\Collection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\CollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class FreeGiftOfferTierRepositoryTest extends TestCase
{
    private FreeGiftOfferTierResource $resource;
    private FreeGiftOfferTierFactory $tierFactory;
    private CollectionFactory $collectionFactory;
    private FreeGiftOfferTierSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private FreeGiftOfferTierRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(FreeGiftOfferTierResource::class);
        $this->tierFactory = $this->createStub(FreeGiftOfferTierFactory::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->searchResultsFactory = $this->createStub(FreeGiftOfferTierSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createStub(CollectionProcessorInterface::class);

        $this->repository = new FreeGiftOfferTierRepository(
            $this->resource,
            $this->tierFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testSaveReturnsSavedTier(): void
    {
        $tier = $this->createStub(FreeGiftOfferTier::class);
        $this->resource->expects(self::once())->method('save')->with($tier);

        self::assertSame($tier, $this->repository->save($tier));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $tier = $this->createStub(FreeGiftOfferTier::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($tier);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdReturnsLoadedTier(): void
    {
        $tier = $this->createStub(FreeGiftOfferTier::class);
        $tier->method('getEntityId')->willReturn(5);
        $this->tierFactory->method('create')->willReturn($tier);

        self::assertSame($tier, $this->repository->getById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdThrowsWhenNotFound(): void
    {
        $tier = $this->createStub(FreeGiftOfferTier::class);
        $tier->method('getEntityId')->willReturn(null);
        $this->tierFactory->method('create')->willReturn($tier);

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

        $searchResults = $this->createMock(FreeGiftOfferTierSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $tier = $this->createStub(FreeGiftOfferTier::class);
        $tier->method('getEntityId')->willReturn(5);
        $this->tierFactory->method('create')->willReturn($tier);
        $this->resource->expects(self::once())->method('delete')->with($tier);

        self::assertTrue($this->repository->deleteById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $tier = $this->createStub(FreeGiftOfferTier::class);
        $tier->method('getEntityId')->willReturn(null);
        $this->tierFactory->method('create')->willReturn($tier);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteWrapsExceptionInCouldNotSaveException(): void
    {
        $tier = $this->createStub(FreeGiftOfferTier::class);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->delete($tier);
    }
}
