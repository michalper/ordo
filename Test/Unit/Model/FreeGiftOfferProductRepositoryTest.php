<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\FreeGiftOfferProductSearchResultsInterface;
use Ordo\Automation\Api\Data\FreeGiftOfferProductSearchResultsInterfaceFactory;
use Ordo\Automation\Model\FreeGiftOfferProduct;
use Ordo\Automation\Model\FreeGiftOfferProductFactory;
use Ordo\Automation\Model\FreeGiftOfferProductRepository;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct as FreeGiftOfferProductResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\Collection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct\CollectionFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class FreeGiftOfferProductRepositoryTest extends TestCase
{
    private FreeGiftOfferProductResource $resource;
    private FreeGiftOfferProductFactory $productFactory;
    private CollectionFactory $collectionFactory;
    private FreeGiftOfferProductSearchResultsInterfaceFactory $searchResultsFactory;
    private CollectionProcessorInterface $collectionProcessor;
    private FreeGiftOfferProductRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(FreeGiftOfferProductResource::class);
        $this->productFactory = $this->createStub(FreeGiftOfferProductFactory::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->searchResultsFactory = $this->createStub(FreeGiftOfferProductSearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createStub(CollectionProcessorInterface::class);

        $this->repository = new FreeGiftOfferProductRepository(
            $this->resource,
            $this->productFactory,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor
        );
    }

    public function testSaveReturnsSavedProduct(): void
    {
        $product = $this->createStub(FreeGiftOfferProduct::class);
        $this->resource->expects(self::once())->method('save')->with($product);

        self::assertSame($product, $this->repository->save($product));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSaveWrapsExceptionInCouldNotSaveException(): void
    {
        $product = $this->createStub(FreeGiftOfferProduct::class);
        $this->resource->method('save')->willThrowException(new \Exception('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($product);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdReturnsLoadedProduct(): void
    {
        $product = $this->createStub(FreeGiftOfferProduct::class);
        $product->method('getEntityId')->willReturn(5);
        $this->productFactory->method('create')->willReturn($product);

        self::assertSame($product, $this->repository->getById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetByIdThrowsWhenNotFound(): void
    {
        $product = $this->createStub(FreeGiftOfferProduct::class);
        $product->method('getEntityId')->willReturn(null);
        $this->productFactory->method('create')->willReturn($product);

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

        $searchResults = $this->createMock(FreeGiftOfferProductSearchResultsInterface::class);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($criteria);
        $searchResults->expects(self::once())->method('setItems')->with([]);
        $searchResults->expects(self::once())->method('setTotalCount')->with(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        self::assertSame($searchResults, $this->repository->getList($criteria));
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $product = $this->createStub(FreeGiftOfferProduct::class);
        $product->method('getEntityId')->willReturn(5);
        $this->productFactory->method('create')->willReturn($product);
        $this->resource->expects(self::once())->method('delete')->with($product);

        self::assertTrue($this->repository->deleteById(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteByIdThrowsWhenNotFound(): void
    {
        $product = $this->createStub(FreeGiftOfferProduct::class);
        $product->method('getEntityId')->willReturn(null);
        $this->productFactory->method('create')->willReturn($product);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteWrapsExceptionInCouldNotSaveException(): void
    {
        $product = $this->createStub(FreeGiftOfferProduct::class);
        $this->resource->method('delete')->willThrowException(new \Exception('locked'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->delete($product);
    }
}
