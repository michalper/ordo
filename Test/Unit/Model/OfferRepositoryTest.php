<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Ordo\Automation\Api\Data\OfferSearchResultsInterface;
use Ordo\Automation\Api\Data\OfferSearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\Offer;
use Ordo\Automation\Model\OfferFactory;
use Ordo\Automation\Model\OfferRepository;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;
use Ordo\Automation\Model\ResourceModel\Offer\Collection;
use Ordo\Automation\Model\ResourceModel\Offer\CollectionFactory;
use PHPUnit\Framework\TestCase;

class OfferRepositoryTest extends TestCase
{
    private OfferResource $resource;
    private OfferFactory $offerFactory;
    private CollectionFactory $collectionFactory;
    private OfferSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private OfferRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(OfferResource::class);
        $this->offerFactory = $this->createMock(OfferFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(OfferSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);

        $this->repository = new OfferRepository(
            $this->resource,
            $this->offerFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testSaveReturnsSavedOffer(): void
    {
        $offer = $this->createMock(Offer::class);
        $this->resource->expects(self::once())->method('save')->with($offer);

        self::assertSame($offer, $this->repository->save($offer));
    }

    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $offer = $this->createMock(Offer::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($offer);
    }

    public function testGetByIdReturnsLoadedOffer(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getEntityId')->willReturn(5);
        $this->offerFactory->method('create')->willReturn($offer);

        self::assertSame($offer, $this->repository->getById(5));
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getEntityId')->willReturn(null);
        $this->offerFactory->method('create')->willReturn($offer);

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

        $searchResults = $this->createMock(OfferSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteReturnsTrue(): void
    {
        $offer = $this->createMock(Offer::class);
        $this->resource->expects(self::once())->method('delete')->with($offer);

        self::assertTrue($this->repository->delete($offer));
    }

    public function testDeleteWrapsExceptionInCouldNotSaveException(): void
    {
        $offer = $this->createMock(Offer::class);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->delete($offer);
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getEntityId')->willReturn(5);
        $this->offerFactory->method('create')->willReturn($offer);
        $this->resource->expects(self::once())->method('delete')->with($offer);

        self::assertTrue($this->repository->deleteById(5));
    }

    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getEntityId')->willReturn(null);
        $this->offerFactory->method('create')->willReturn($offer);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }
}
