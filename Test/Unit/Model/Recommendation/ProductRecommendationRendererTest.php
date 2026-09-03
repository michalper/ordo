<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Recommendation;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Ordo\Automation\Model\Recommendation\ProductRecommendationRenderer;
use PHPUnit\Framework\TestCase;

class ProductRecommendationRendererTest extends TestCase
{
    private ProductRepositoryInterface $productRepository;
    private Escaper $escaper;
    private PricingHelper $pricingHelper;

    protected function setUp(): void
    {
        $this->productRepository = $this->createStub(ProductRepositoryInterface::class);
        $this->escaper = new Escaper();
        $this->pricingHelper = $this->createStub(PricingHelper::class);
        $this->pricingHelper->method('currency')->willReturnCallback(
            static fn (float $amount) => '$' . number_format($amount, 2)
        );
    }

    private function makeRenderer(): ProductRecommendationRenderer
    {
        return new ProductRecommendationRenderer($this->productRepository, $this->escaper, $this->pricingHelper);
    }

    public function testReturnsEmptyStringForEmptySkuList(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects(self::never())->method('get');
        $this->productRepository = $productRepository;

        self::assertSame('', $this->makeRenderer()->renderHtml([]));
    }

    public function testSkipsSkuThatFailsToResolve(): void
    {
        $this->productRepository->method('get')->willThrowException(
            new NoSuchEntityException(__('No such product'))
        );

        self::assertSame('', $this->makeRenderer()->renderHtml(['MISSING-SKU']));
    }

    public function testEscapesProductNameAndFormatsPrice(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('<b>Widget</b> & Co');
        $product->method('getProductUrl')->willReturn('https://example.com/widget.html');
        $product->method('getFinalPrice')->willReturn(19.99);

        $this->productRepository->method('get')->willReturn($product);

        $html = $this->makeRenderer()->renderHtml(['SKU-1']);

        self::assertStringNotContainsString('<b>Widget</b>', $html);
        self::assertStringContainsString('&lt;b&gt;Widget&lt;/b&gt; &amp; Co', $html);
        self::assertStringContainsString('https://example.com/widget.html', $html);
        self::assertStringContainsString('$19.99', $html);
    }

    public function testReturnsEmptyStringWhenEveryProductFailsToResolve(): void
    {
        $this->productRepository->method('get')->willThrowException(
            new NoSuchEntityException(__('No such product'))
        );

        self::assertSame('', $this->makeRenderer()->renderHtml(['SKU-1', 'SKU-2']));
    }

    public function testUsesCustomHeadingWhenProvided(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('Widget');
        $product->method('getProductUrl')->willReturn('https://example.com/widget.html');
        $product->method('getFinalPrice')->willReturn(19.99);

        $this->productRepository->method('get')->willReturn($product);

        $html = $this->makeRenderer()->renderHtml(['SKU-1'], 'New Arrivals');

        self::assertStringContainsString('New Arrivals', $html);
        self::assertStringNotContainsString('Recommended for you', $html);
    }
}
