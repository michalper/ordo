<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Ordo\Automation\Model\ProductFeed\CatalogFeedProductFetcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CatalogFeedProductFetcherTest extends TestCase
{
    private ProductCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $productCollectionFactory;
    private CatalogImageHelper&\PHPUnit\Framework\MockObject\MockObject $catalogImageHelper;
    private CatalogFeedProductFetcher $fetcher;

    protected function setUp(): void
    {
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->catalogImageHelper = $this->createMock(CatalogImageHelper::class);
        $this->fetcher = new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper);
    }

    private function makeProduct(string $sku): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }

    /**
     * Regression test for the ROADMAP.md Tier 4 "unbounded ... single-pass memory build" gap:
     * fetchByPage() pages through the catalog, setCurPage()-ing and clear()-ing the SAME
     * collection object across pages, rather than loading the whole catalog in one shot. Moved
     * here (out of each generator's own test, where it used to live duplicated three times) once
     * ADR 0022 took the catalog walk out of the generators entirely.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testFetchByPagePagesThroughMultiplePagesOfTheSameCollection(): void
    {
        $pageOneProducts = [$this->makeProduct('SKU1')];
        $pageTwoProducts = [$this->makeProduct('SKU2')];

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStore')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addUrlRewrite')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addFinalPrice')->willReturnSelf();
        $collection->method('joinField')->willReturnSelf();
        $collection->expects(self::once())->method('setPageSize')->with(500);
        $collection->expects(self::exactly(2))->method('setCurPage')->with(self::logicalOr(1, 2));
        $collection->expects(self::exactly(2))->method('load');
        $collection->expects(self::exactly(2))->method('clear');
        $collection->method('getLastPageNumber')->willReturn(2);

        $seenPages = 0;
        $collection->method('getIterator')->willReturnCallback(
            function () use (&$seenPages, $pageOneProducts, $pageTwoProducts) {
                $seenPages++;
                return new \ArrayIterator($seenPages === 1 ? $pageOneProducts : $pageTwoProducts);
            }
        );

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $products = iterator_to_array($this->fetcher->fetchByPage(1));

        self::assertCount(2, $products);
        self::assertSame('SKU1', $products[0]->getSku());
        self::assertSame('SKU2', $products[1]->getSku());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFetchByPageRunsTheLoopBodyOnceEvenForAnEmptyCatalog(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStore')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addUrlRewrite')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addFinalPrice')->willReturnSelf();
        $collection->method('joinField')->willReturnSelf();
        $collection->expects(self::once())->method('load');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $collection->method('getLastPageNumber')->willReturn(0);

        $this->productCollectionFactory->method('create')->willReturn($collection);

        $products = iterator_to_array($this->fetcher->fetchByPage(1));

        self::assertSame([], $products);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetImageUrlReturnsNullWhenHelperResolvesToPlaceholder(): void
    {
        $product = $this->createStub(Product::class);
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('');

        self::assertNull($this->fetcher->getImageUrl($product));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetImageUrlReturnsTheResolvedUrl(): void
    {
        $product = $this->createStub(Product::class);
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/1.jpg');

        self::assertSame('https://example.test/media/1.jpg', $this->fetcher->getImageUrl($product));
    }
}
