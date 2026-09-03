<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock\Producer;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\CategoryProductLister;
use Ordo\Automation\Model\ContentBlock\Producer\ProductFeedProducer;
use Ordo\Automation\Model\ContentBlock\RuleProductLister;
use Ordo\Automation\Model\Recommendation\ProductRecommendationRenderer;
use PHPUnit\Framework\TestCase;

class ProductFeedProducerTest extends TestCase
{
    private CategoryProductLister&\PHPUnit\Framework\MockObject\MockObject $categoryProductLister;
    private RuleProductLister&\PHPUnit\Framework\MockObject\MockObject $ruleProductLister;
    private ProductRecommendationRenderer&\PHPUnit\Framework\MockObject\MockObject $productRecommendationRenderer;
    private ProductFeedProducer $producer;

    protected function setUp(): void
    {
        $this->categoryProductLister = $this->createMock(CategoryProductLister::class);
        $this->ruleProductLister = $this->createMock(RuleProductLister::class);
        $this->productRecommendationRenderer = $this->createMock(ProductRecommendationRenderer::class);
        $this->producer = new ProductFeedProducer(
            $this->categoryProductLister,
            $this->ruleProductLister,
            $this->productRecommendationRenderer
        );
    }

    private function makeBlock(array $config): ContentBlock
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $block->setConfigArray($config);

        return $block;
    }

    public function testSourceCategoryCallsCategoryListerThenRenderer(): void
    {
        $this->categoryProductLister->expects(self::once())
            ->method('getSkus')
            ->with(15, 4)
            ->willReturn(['SKU-1', 'SKU-2']);
        $this->ruleProductLister->expects(self::never())->method('getSkus');
        $this->productRecommendationRenderer->expects(self::once())
            ->method('renderHtml')
            ->with(['SKU-1', 'SKU-2'], 'New Arrivals')
            ->willReturn('<div>rendered</div>');

        $html = $this->producer->render($this->makeBlock(['source' => 'category', 'category_id' => 15]));

        self::assertSame('<div>rendered</div>', $html);
    }

    public function testSourceRuleCallsRuleListerThenRenderer(): void
    {
        $this->ruleProductLister->expects(self::once())
            ->method('getSkus')
            ->with(3, 4)
            ->willReturn(['SKU-9']);
        $this->categoryProductLister->expects(self::never())->method('getSkus');
        $this->productRecommendationRenderer->expects(self::once())
            ->method('renderHtml')
            ->with(['SKU-9'], 'New Arrivals')
            ->willReturn('<div>rule rendered</div>');

        $html = $this->producer->render($this->makeBlock(['source' => 'rule', 'rule_id' => 3]));

        self::assertSame('<div>rule rendered</div>', $html);
    }

    public function testUnknownSourceResolvesNoSkusAndSkipsRenderer(): void
    {
        $this->categoryProductLister->expects(self::never())->method('getSkus');
        $this->ruleProductLister->expects(self::never())->method('getSkus');
        $this->productRecommendationRenderer->expects(self::never())->method('renderHtml');

        $html = $this->producer->render($this->makeBlock(['source' => 'unknown_source']));

        self::assertSame('', $html);
    }

    public function testMissingSourceResolvesNoSkusAndSkipsRenderer(): void
    {
        $this->categoryProductLister->expects(self::never())->method('getSkus');
        $this->ruleProductLister->expects(self::never())->method('getSkus');
        $this->productRecommendationRenderer->expects(self::never())->method('renderHtml');

        $html = $this->producer->render($this->makeBlock([]));

        self::assertSame('', $html);
    }

    public function testEmptySkuListFromListerSkipsRenderer(): void
    {
        $this->categoryProductLister->expects(self::once())->method('getSkus')->willReturn([]);
        $this->ruleProductLister->expects(self::never())->method('getSkus');
        $this->productRecommendationRenderer->expects(self::never())->method('renderHtml');

        $html = $this->producer->render($this->makeBlock(['source' => 'category', 'category_id' => 15]));

        self::assertSame('', $html);
    }

    public function testHonorsCustomItemCount(): void
    {
        $this->categoryProductLister->expects(self::once())->method('getSkus')->with(15, 10)->willReturn([]);
        $this->ruleProductLister->expects(self::never())->method('getSkus');
        $this->productRecommendationRenderer->expects(self::never())->method('renderHtml');

        $this->producer->render($this->makeBlock(['source' => 'category', 'category_id' => 15, 'item_count' => 10]));
    }

    public function testNonPositiveItemCountFallsBackToDefault(): void
    {
        $this->categoryProductLister->expects(self::once())->method('getSkus')->with(15, 4)->willReturn([]);
        $this->ruleProductLister->expects(self::never())->method('getSkus');
        $this->productRecommendationRenderer->expects(self::never())->method('renderHtml');

        $this->producer->render($this->makeBlock(['source' => 'category', 'category_id' => 15, 'item_count' => -5]));
    }
}
