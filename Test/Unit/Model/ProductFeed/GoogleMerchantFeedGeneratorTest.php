<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ProductFeed\GoogleMerchantFeedGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class GoogleMerchantFeedGeneratorTest extends TestCase
{
    private ProductCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $productCollectionFactory;
    private CatalogImageHelper&\PHPUnit\Framework\MockObject\MockObject $catalogImageHelper;
    private StoreManagerInterface $storeManager;
    private Config $config;
    private GoogleMerchantFeedGenerator $generator;

    protected function setUp(): void
    {
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->catalogImageHelper = $this->createMock(CatalogImageHelper::class);

        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getBaseUrl')->willReturn('https://example.test/');
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config = $this->createStub(Config::class);
        $this->config->method('getShoppingFeedTitle')->willReturn('My Store Feed');
        $this->config->method('getShoppingFeedDescription')->willReturn('Feed description');

        $this->generator = new GoogleMerchantFeedGenerator(
            $this->productCollectionFactory,
            $this->catalogImageHelper,
            $this->storeManager,
            $this->config
        );
    }

    private function makeCollection(array $products): ProductCollection
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addFinalPrice')->willReturnSelf();
        $collection->method('joinField')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        return $collection;
    }

    private function makeProduct(
        string $sku,
        string $name,
        string $url,
        float $price,
        bool $inStock,
        string $description = 'A great product'
    ): Product {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn($name);
        $product->method('getProductUrl')->willReturn($url);
        $product->method('getFinalPrice')->willReturn($price);
        $product->method('getData')->willReturnMap([
            ['description', $description],
            ['is_in_stock', $inStock],
        ]);

        return $product;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateRendersAValidItemForEachInStockAndOutOfStockProduct(): void
    {
        $inStockProduct = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 19.99, true);
        $outOfStockProduct = $this->makeProduct('SKU2', 'Product Two', 'https://example.test/product-two.html', 29.5, false);

        $this->productCollectionFactory->method('create')
            ->willReturn($this->makeCollection([$inStockProduct, $outOfStockProduct]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/catalog/product/1.jpg');

        $result = $this->generator->generate();

        self::assertSame(2, $result['productCount']);
        self::assertStringContainsString('<g:id>SKU1</g:id>', $result['xml']);
        self::assertStringContainsString('<g:price>19.99 USD</g:price>', $result['xml']);
        self::assertStringContainsString('<g:availability>in stock</g:availability>', $result['xml']);
        self::assertStringContainsString('<g:id>SKU2</g:id>', $result['xml']);
        self::assertStringContainsString('<g:availability>out of stock</g:availability>', $result['xml']);
        self::assertStringContainsString('<title>My Store Feed</title>', $result['xml']);
        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $result['xml']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateSkipsProductWithNoResolvableImage(): void
    {
        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 19.99, true);

        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn(null);

        $result = $this->generator->generate();

        self::assertSame(0, $result['productCount']);
        self::assertStringNotContainsString('SKU1', $result['xml']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateSkipsProductWithZeroOrNegativePrice(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('SKU1');
        $product->method('getName')->willReturn('Product One');
        $product->method('getProductUrl')->willReturn('https://example.test/product-one.html');
        $product->method('getFinalPrice')->willReturn(0.0);

        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));

        $result = $this->generator->generate();

        self::assertSame(0, $result['productCount']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateReturnsEmptyFeedWhenNoProducts(): void
    {
        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([]));

        $result = $this->generator->generate();

        self::assertSame(0, $result['productCount']);
        self::assertStringContainsString('<channel>', $result['xml']);
    }

    /**
     * Regression test for the ROADMAP.md Tier 4 "unbounded ... single-pass memory build" gap:
     * generate() used to load the whole catalog collection in one shot - now it pages through it,
     * setCurPage()-ing and clear()-ing the SAME collection object across pages rather than loading
     * everything at once.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGeneratePagesThroughMultiplePagesOfTheSameCollection(): void
    {
        $pageOneProducts = [$this->makeProduct('SKU1', 'Product One', 'https://example.test/p1.html', 10.0, true)];
        $pageTwoProducts = [$this->makeProduct('SKU2', 'Product Two', 'https://example.test/p2.html', 20.0, true)];

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addFinalPrice')->willReturnSelf();
        $collection->method('joinField')->willReturnSelf();
        $collection->expects(self::once())->method('setPageSize')->with(500);
        $collection->expects(self::exactly(2))->method('setCurPage')->with(self::logicalOr(1, 2));
        $collection->expects(self::exactly(2))->method('load');
        $collection->expects(self::exactly(2))->method('clear');
        $collection->method('getLastPageNumber')->willReturn(2);

        $seenPages = 0;
        $collection->method('getIterator')->willReturnCallback(function () use (&$seenPages, $pageOneProducts, $pageTwoProducts) {
            $seenPages++;
            return new \ArrayIterator($seenPages === 1 ? $pageOneProducts : $pageTwoProducts);
        });

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/1.jpg');

        $result = $this->generator->generate();

        self::assertSame(2, $result['productCount']);
        self::assertStringContainsString('<g:id>SKU1</g:id>', $result['xml']);
        self::assertStringContainsString('<g:id>SKU2</g:id>', $result['xml']);
    }
}
