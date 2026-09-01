<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferSearchResultsInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferSearchResultsInterfaceFactory;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\FreeGiftOfferRepository;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class FreeGiftOfferRepositoryTest extends TestCase
{
    private FreeGiftOfferResource $resource;
    private FreeGiftOfferFactory $offerFactory;
    private CollectionFactory $collectionFactory;
    private FreeGiftOfferSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private FreeGiftOfferRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(FreeGiftOfferResource::class);
        $this->offerFactory = $this->createStub(FreeGiftOfferFactory::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->searchResultsFactory = $this->createStub(FreeGiftOfferSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createStub(CollectionProcessorInterface::class);

        $this->repository = new FreeGiftOfferRepository(
            $this->resource,
            $this->offerFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testSaveReturnsSavedOffer(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $this->resource->expects(self::once())->method('save')->with($offer);

        self::assertSame($offer, $this->repository->save($offer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($offer);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdReturnsLoadedOffer(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(5);
        $this->offerFactory->method('create')->willReturn($offer);

        self::assertSame($offer, $this->repository->getById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdThrowsWhenNotFound(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(null);
        $this->offerFactory->method('create')->willReturn($offer);

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

        $searchResults = $this->createMock(FreeGiftOfferSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(5);
        $this->offerFactory->method('create')->willReturn($offer);
        $this->resource->expects(self::once())->method('delete')->with($offer);

        self::assertTrue($this->repository->deleteById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(null);
        $this->offerFactory->method('create')->willReturn($offer);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteWrapsExceptionInCouldNotSaveException(): void
    {
        $offer = $this->createStub(FreeGiftOffer::class);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->delete($offer);
    }
}
