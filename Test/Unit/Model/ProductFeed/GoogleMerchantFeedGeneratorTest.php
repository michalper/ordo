<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Directory\Model\Currency;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ProductFeed\CatalogFeedProductFetcher;
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
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn('https://example.test/media/catalog/product/1.jpg');

        $baseCurrency = $this->createStub(Currency::class);
        // Identity conversion by default (base currency == display currency) - the dedicated
        // currency-conversion regression test below stubs a real rate instead.
        $baseCurrency->method('convert')->willReturnCallback(fn (float $price) => $price);

        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getBaseUrl')->willReturn('https://example.test/');
        $store->method('getBaseCurrency')->willReturn($baseCurrency);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config = $this->createStub(Config::class);
        $this->config->method('getShoppingFeedTitle')->willReturn('My Store Feed');
        $this->config->method('getShoppingFeedDescription')->willReturn('Feed description');

        $this->generator = new GoogleMerchantFeedGenerator(
            new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper),
            $this->storeManager,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFeedCodeReturnsGoogleMerchant(): void
    {
        self::assertSame('google_merchant', $this->generator->getFeedCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContentTypeReturnsXml(): void
    {
        self::assertSame('application/xml; charset=UTF-8', $this->generator->getContentType());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsEnabledDelegatesToConfig(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->expects(self::once())->method('isShoppingFeedEnabled')->with(3)->willReturn(true);
        $this->generator = new GoogleMerchantFeedGenerator(
            new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper),
            $this->storeManager,
            $this->config
        );

        self::assertTrue($this->generator->isEnabled(3));
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

        $this->generator->startFeed(1);
        $this->generator->addProduct($inStockProduct);
        $this->generator->addProduct($outOfStockProduct);
        $result = $this->generator->finishFeed();

        self::assertSame(2, $result['productCount']);
        self::assertStringContainsString('<g:id>SKU1</g:id>', $result['content']);
        self::assertStringContainsString('<g:price>19.99 USD</g:price>', $result['content']);
        self::assertStringContainsString('<g:availability>in stock</g:availability>', $result['content']);
        self::assertStringContainsString('<g:id>SKU2</g:id>', $result['content']);
        self::assertStringContainsString('<g:availability>out of stock</g:availability>', $result['content']);
        self::assertStringContainsString('<title>My Store Feed</title>', $result['content']);
        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $result['content']);
    }

    /**
     * Regression test: getFinalPrice() is base currency (catalog_product_index_price), so it
     * must be converted through the store's base->current currency rate before being tagged
     * with the display currency code - not emitted unconverted alongside the display currency
     * code, which would silently mislabel the price on any store where display != base currency.
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
        $store->method('getBaseUrl')->willReturn('https://example.test/');
        $store->method('getBaseCurrency')->willReturn($baseCurrency);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->generator = new GoogleMerchantFeedGenerator(
            new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper),
            $this->storeManager,
            $this->config
        );

        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 100.0, true);

        $this->generator->startFeed(1);
        $this->generator->addProduct($product);
        $result = $this->generator->finishFeed();

        self::assertStringContainsString('<g:price>90.00 EUR</g:price>', $result['content']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateSkipsProductWithNoResolvableImage(): void
    {
        $this->catalogImageHelper = $this->createMock(CatalogImageHelper::class);
        $this->catalogImageHelper->method('init')->willReturnSelf();
        $this->catalogImageHelper->method('getUrl')->willReturn(null);
        $this->generator = new GoogleMerchantFeedGenerator(
            new CatalogFeedProductFetcher($this->productCollectionFactory, $this->catalogImageHelper),
            $this->storeManager,
            $this->config
        );

        $product = $this->makeProduct('SKU1', 'Product One', 'https://example.test/product-one.html', 19.99, true);

        $this->generator->startFeed(1);
        $this->generator->addProduct($product);
        $result = $this->generator->finishFeed();

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

        $this->generator->startFeed(1);
        $this->generator->addProduct($product);
        $result = $this->generator->finishFeed();

        self::assertSame(0, $result['productCount']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateReturnsEmptyFeedWhenNoProducts(): void
    {
        $this->generator->startFeed(1);
        $result = $this->generator->finishFeed();

        self::assertSame(0, $result['productCount']);
        self::assertStringContainsString('<channel>', $result['content']);
    }
}
