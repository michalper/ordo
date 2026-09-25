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
use Ordo\Automation\Model\ProductFeed\AiAgentFeedGenerator;
use Ordo\Automation\Model\ProductFeed\CatalogFeedProductFetcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AiAgentFeedGeneratorTest extends TestCase
{
    private ProductCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $productCollectionFactory;
    private CatalogImageHelper&\PHPUnit\Framework\MockObject\MockObject $catalogImageHelper;
    private StoreManagerInterface $storeManager;
    private Config $config;
    private AiAgentFeedGenerator $generator;

    protected function setUp(): void
    {
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->catalogImageHelper = $this->createMock(CatalogImageHelper::class);

        $baseCurrency = $this->createStub(Currency::class);
        $baseCurrency->method('convert')->willReturnCallback(fn (float $price) => $price);

        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getBaseCurrency')->willReturn($baseCurrency);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config = $this->createStub(Config::class);

        $this->generator = new AiAgentFeedGenerator(
            new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper),
            $this->storeManager,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFeedCodeReturnsAiAgent(): void
    {
        self::assertSame('ai_agent', $this->generator->getFeedCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContentTypeReturnsJson(): void
    {
        self::assertSame('application/json; charset=UTF-8', $this->generator->getContentType());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsEnabledDelegatesToConfig(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->expects(self::once())->method('isAiAgentEnabled')->with(3)->willReturn(true);
        $this->generator = new AiAgentFeedGenerator(
            new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper),
            $this->storeManager,
            $this->config
        );

        self::assertTrue($this->generator->isEnabled(3));
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
    public function testGenerateRendersAJsonProductForEachInStockAndOutOfStockProduct(): void
    {
        $inStockProduct = $this->makeProduct('SKU1', 'Product One', 'https://example.test/p1.html', 19.99, true);
        $outOfStockProduct = $this->makeProduct('SKU2', 'Product Two', 'https://example.test/p2.html', 29.5, false);

        $this->productCollectionFactory->method('create')
            ->willReturn($this->makeCollection([$inStockProduct, $outOfStockProduct]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/1.jpg');

        $result = $this->generator->generate(1);
        $decoded = json_decode($result['content'], true);

        self::assertSame(2, $result['productCount']);
        self::assertCount(2, $decoded['products']);
        self::assertSame('SKU1', $decoded['products'][0]['sku']);
        self::assertSame(19.99, $decoded['products'][0]['price']);
        self::assertSame('USD', $decoded['products'][0]['currency']);
        self::assertTrue($decoded['products'][0]['in_stock']);
        self::assertFalse($decoded['products'][1]['in_stock']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateSkipsProductWithNoResolvableImage(): void
    {
        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/p1.html', 19.99, true);

        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn(null);

        $result = $this->generator->generate(1);

        self::assertSame(0, $result['productCount']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateSkipsProductWithZeroOrNegativePrice(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('SKU1');
        $product->method('getName')->willReturn('Product One');
        $product->method('getProductUrl')->willReturn('https://example.test/p1.html');
        $product->method('getFinalPrice')->willReturn(0.0);

        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));

        $result = $this->generator->generate(1);

        self::assertSame(0, $result['productCount']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateReturnsEmptyFeedWhenNoProducts(): void
    {
        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([]));

        $result = $this->generator->generate(1);

        self::assertSame(0, $result['productCount']);
        self::assertSame(['products' => []], json_decode($result['content'], true));
    }

    /**
     * Regression: getFinalPrice() is base currency, must be converted to the store's display
     * currency before being tagged with that currency code - same reasoning as
     * GoogleMerchantFeedGeneratorTest::testGenerateConvertsBasePriceToDisplayCurrencyBeforeEmitting().
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateConvertsBasePriceToDisplayCurrencyBeforeEmitting(): void
    {
        $baseCurrency = $this->createStub(Currency::class);
        $baseCurrency->method('convert')->willReturnCallback(
            fn (float $price, string $toCurrency) => $toCurrency === 'EUR' ? $price * 0.9 : $price
        );

        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('EUR');
        $store->method('getBaseCurrency')->willReturn($baseCurrency);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->generator = new AiAgentFeedGenerator(
            new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper),
            $this->storeManager,
            $this->config
        );

        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/p1.html', 100.0, true);
        $this->productCollectionFactory->method('create')->willReturn($this->makeCollection([$product]));
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/1.jpg');

        $result = $this->generator->generate(1);
        $decoded = json_decode($result['content'], true);

        self::assertSame(90.0, (float) $decoded['products'][0]['price']);
        self::assertSame('EUR', $decoded['products'][0]['currency']);
    }
}
