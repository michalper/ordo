<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Directory\Model\Currency;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ProductFeed\MetaCatalogFeedGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class MetaCatalogFeedGeneratorTest extends TestCase
{
    private ProductCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $productCollectionFactory;
    private CatalogImageHelper&\PHPUnit\Framework\MockObject\MockObject $catalogImageHelper;
    private StoreManagerInterface $storeManager;
    private Currency&\PHPUnit\Framework\MockObject\MockObject $baseCurrency;
    private Config $config;
    private MetaCatalogFeedGenerator $generator;

    protected function setUp(): void
    {
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->catalogImageHelper = $this->createMock(CatalogImageHelper::class);

        $this->baseCurrency = $this->createMock(Currency::class);

        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getBaseCurrency')->willReturn($this->baseCurrency);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config = $this->createStub(Config::class);
        $this->config->method('getMetaCatalogFeedDefaultBrand')->willReturn('Acme');

        $this->generator = new MetaCatalogFeedGenerator(
            $this->productCollectionFactory,
            $this->catalogImageHelper,
            $this->storeManager,
            $this->config
        );
    }

    private function makeCollection(array $products): ProductCollection
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('setStore')->willReturnSelf();
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
    public function testGetFeedCodeAndContentType(): void
    {
        self::assertSame('meta_catalog', $this->generator->getFeedCode());
        self::assertSame('text/csv; charset=UTF-8', $this->generator->getContentType());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsEnabledDelegatesToConfig(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('isMetaCatalogFeedEnabled')->with(1)->willReturn(true);

        $generator = new MetaCatalogFeedGenerator(
            $this->productCollectionFactory,
            $this->catalogImageHelper,
            $this->storeManager,
            $config
        );

        self::assertTrue($generator->isEnabled(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateRendersACsvRowForEachInStockAndOutOfStockProduct(): void
    {
        $inStockProduct = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 19.99, true);
        $outOfStockProduct = $this->makeProduct('SKU2', 'Product Two', 'https://example.test/product-two.html', 29.5, false);

        $this->productCollectionFactory->method('create')
            ->willReturn($this->makeCollection([$inStockProduct, $outOfStockProduct]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/catalog/product/1.jpg');
        $this->baseCurrency->method('convert')->willReturnCallback(static fn (float $price): float => $price);

        $result = $this->generator->generate(1);

        self::assertSame(2, $result['productCount']);
        self::assertStringStartsWith('id,title,description,availability,condition,price,link,image_link,brand', $result['content']);
        self::assertStringContainsString('SKU1,Product One,A great product,in stock,new,19.99 USD,https://example.test/product-one.html,https://example.test/media/catalog/product/1.jpg,Acme', $result['content']);
        self::assertStringContainsString('SKU2,Product Two,A great product,out of stock,new,29.50 USD', $result['content']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateConvertsBasePriceToDisplayCurrency(): void
    {
        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 10.0, true);

        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/1.jpg');

        $this->baseCurrency->expects(self::once())->method('convert')->with(10.0, 'USD')->willReturn(23.5);

        $result = $this->generator->generate(1);

        self::assertStringContainsString('23.50 USD', $result['content']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateSkipsProductWithNoResolvableImage(): void
    {
        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 19.99, true);

        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn(null);

        $result = $this->generator->generate(1);

        self::assertSame(0, $result['productCount']);
        self::assertStringNotContainsString('SKU1', $result['content']);
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

        $result = $this->generator->generate(1);

        self::assertSame(0, $result['productCount']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateSkipsAllProductsWhenNoDefaultBrandConfigured(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getMetaCatalogFeedDefaultBrand')->willReturn('');

        $generator = new MetaCatalogFeedGenerator(
            $this->productCollectionFactory,
            $this->catalogImageHelper,
            $this->storeManager,
            $config
        );

        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 19.99, true);
        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/1.jpg');

        $result = $generator->generate(1);

        self::assertSame(0, $result['productCount']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateReturnsHeaderOnlyRowWhenNoProducts(): void
    {
        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([]));

        $result = $this->generator->generate(1);

        self::assertSame(0, $result['productCount']);
        self::assertSame("id,title,description,availability,condition,price,link,image_link,brand\r\n", $result['content']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateEscapesFieldsContainingCommasOrQuotes(): void
    {
        $product = $this->makeProduct(
            'SKU1',
            'Product, "One"',
            'https://example.test/product-one.html',
            19.99,
            true
        );

        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/1.jpg');
        $this->baseCurrency->method('convert')->willReturnCallback(static fn (float $price): float => $price);

        $result = $this->generator->generate(1);

        self::assertStringContainsString('"Product, ""One"""', $result['content']);
    }

    /**
     * Regression test mirroring GoogleMerchantFeedGeneratorTest's own: generate() must page
     * through the catalog rather than load it all in one shot.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGeneratePagesThroughMultiplePagesOfTheSameCollection(): void
    {
        $pageOneProducts = [$this->makeProduct('SKU1', 'Product One', 'https://example.test/p1.html', 10.0, true)];
        $pageTwoProducts = [$this->makeProduct('SKU2', 'Product Two', 'https://example.test/p2.html', 20.0, true)];

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStore')->willReturnSelf();
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
        $this->baseCurrency->method('convert')->willReturnCallback(static fn (float $price): float => $price);

        $result = $this->generator->generate(1);

        self::assertSame(2, $result['productCount']);
        self::assertStringContainsString('SKU1', $result['content']);
        self::assertStringContainsString('SKU2', $result['content']);
    }
}
