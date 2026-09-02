<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\AddProductRecommendations;
use Ordo\Automation\Model\Recommendation\ProductRecommendationRenderer;
use Ordo\Automation\Model\Recommendation\ProductRecommender;
use PHPUnit\Framework\TestCase;

class AddProductRecommendationsTest extends TestCase
{
    private ProductRecommender&\PHPUnit\Framework\MockObject\MockObject $productRecommender;
    private ProductRecommendationRenderer&\PHPUnit\Framework\MockObject\MockObject $productRecommendationRenderer;
    private AddProductRecommendations $action;

    protected function setUp(): void
    {
        $this->productRecommender = $this->createMock(ProductRecommender::class);
        $this->productRecommendationRenderer = $this->createMock(ProductRecommendationRenderer::class);
        $this->action = new AddProductRecommendations($this->productRecommender, $this->productRecommendationRenderer);
    }

    public function testDoesNothingWhenCustomerIdIsMissing(): void
    {
        $this->productRecommender->expects(self::never())->method('getRecommendedSkus');
        $this->productRecommendationRenderer->expects(self::never())->method('renderHtml');

        $context = [];
        $this->action->execute($context, []);

        self::assertArrayNotHasKey('recommended_products_html', $context);
    }

    public function testDoesNothingWhenCustomerIdIsZero(): void
    {
        $this->productRecommender->expects(self::never())->method('getRecommendedSkus');
        $this->productRecommendationRenderer->expects(self::never())->method('renderHtml');

        $context = ['customer_id' => 0];
        $this->action->execute($context, []);
    }

    public function testUsesDefaultCountOfFourWhenParamsAreMissing(): void
    {
        $this->productRecommender->expects(self::once())
            ->method('getRecommendedSkus')
            ->with(42, 4)
            ->willReturn(['SKU-1']);
        $this->productRecommendationRenderer->expects(self::once())
            ->method('renderHtml')
            ->with(['SKU-1'])
            ->willReturn('<div>html</div>');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);

        self::assertSame('<div>html</div>', $context['recommended_products_html']);
    }

    public function testUsesDefaultCountWhenCountIsNonNumericOrZero(): void
    {
        $this->productRecommender->expects(self::once())
            ->method('getRecommendedSkus')
            ->with(42, 4)
            ->willReturn([]);
        $this->productRecommendationRenderer->expects(self::once())->method('renderHtml')->willReturn('');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['count' => 'not-a-number']);
    }

    public function testHonorsCustomCountParam(): void
    {
        $this->productRecommender->expects(self::once())
            ->method('getRecommendedSkus')
            ->with(42, 8)
            ->willReturn([]);
        $this->productRecommendationRenderer->expects(self::once())->method('renderHtml')->willReturn('');

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['count' => '8']);
    }

    public function testSetsEmptyRecommendedProductsHtmlWhenRendererReturnsEmptyString(): void
    {
        $this->productRecommender->expects(self::once())->method('getRecommendedSkus')->willReturn([]);
        $this->productRecommendationRenderer->expects(self::once())->method('renderHtml')->willReturn('');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);

        self::assertSame('', $context['recommended_products_html']);
    }
}
