<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Combine;
use Magento\SalesRule\Model\RuleFactory;
use Ordo\Automation\Model\ContentBlock\RuleProductLister;
use PHPUnit\Framework\TestCase;

class RuleProductListerTest extends TestCase
{
    private RuleFactory&\PHPUnit\Framework\MockObject\MockObject $ruleFactory;
    private ProductCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $productCollectionFactory;
    private RuleProductLister $lister;

    protected function setUp(): void
    {
        $this->ruleFactory = $this->createMock(RuleFactory::class);
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->lister = new RuleProductLister($this->ruleFactory, $this->productCollectionFactory);
    }

    private function makeProductMock(string $sku): Product&\PHPUnit\Framework\MockObject\MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }

    /**
     * Rule::getRuleId() is a magic accessor (via DataObject::__call(), never declared on Rule
     * itself), so PHPUnit can't configure it directly with method() — mocking __call() itself (a
     * real, declared method) is the supported way to stub it. getActions(), unlike getRuleId(),
     * IS a real declared method (Magento\Rule\Model\AbstractModel::getActions()), so it takes
     * priority over __call and must be configured directly instead.
     */
    private function makeRuleMock(?int $ruleId, ?Combine $actions = null): Rule&\PHPUnit\Framework\MockObject\MockObject
    {
        $rule = $this->createMock(Rule::class);
        $rule->expects(self::once())->method('load')->with(3);
        $rule->method('__call')->willReturnCallback(static fn (string $method, array $args) => match ($method) {
            'getRuleId' => $ruleId,
            default => null,
        });
        $rule->method('getActions')->willReturn($actions);

        return $rule;
    }

    public function testReturnsEmptyArrayWhenRuleIdIsZeroOrNegative(): void
    {
        $this->ruleFactory->expects(self::never())->method('create');
        $this->productCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->lister->getSkus(0, 4));
        self::assertSame([], $this->lister->getSkus(-1, 4));
    }

    public function testReturnsEmptyArrayWhenLimitIsZeroOrNegative(): void
    {
        $this->ruleFactory->expects(self::never())->method('create');
        $this->productCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->lister->getSkus(3, 0));
        self::assertSame([], $this->lister->getSkus(3, -1));
    }

    public function testReturnsEmptyArrayWhenRuleFailsToLoad(): void
    {
        $rule = $this->makeRuleMock(null);
        $this->ruleFactory->expects(self::once())->method('create')->willReturn($rule);

        $this->productCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->lister->getSkus(3, 4));
    }

    public function testReturnsMatchingSkusUpToLimit(): void
    {
        $combine = $this->createMock(Combine::class);
        $combine->expects(self::exactly(3))->method('validate')->willReturnCallback(
            static fn (Product $product) => in_array($product->getSku(), ['SKU-1', 'SKU-3'], true)
        );

        $rule = $this->makeRuleMock(3, $combine);
        $this->ruleFactory->expects(self::once())->method('create')->willReturn($rule);

        $products = [
            $this->makeProductMock('SKU-1'),
            $this->makeProductMock('SKU-2'),
            $this->makeProductMock('SKU-3'),
        ];
        foreach ($products as $product) {
            $product->expects(self::once())->method('setData')->with('product', $product);
        }

        $collection = $this->createMock(ProductCollection::class);
        $collection->expects(self::once())->method('getItems')->willReturn($products);

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame(['SKU-1', 'SKU-3'], $this->lister->getSkus(3, 4));
    }

    public function testStopsScanningOnceLimitIsReached(): void
    {
        $combine = $this->createMock(Combine::class);
        $combine->expects(self::exactly(2))->method('validate')->willReturn(true);

        $rule = $this->makeRuleMock(3, $combine);
        $this->ruleFactory->expects(self::once())->method('create')->willReturn($rule);

        $products = [
            $this->makeProductMock('SKU-1'),
            $this->makeProductMock('SKU-2'),
            $this->makeProductMock('SKU-3'),
        ];
        $products[0]->expects(self::once())->method('setData')->with('product', $products[0]);
        $products[1]->expects(self::once())->method('setData')->with('product', $products[1]);
        $products[2]->expects(self::never())->method('setData');

        $collection = $this->createMock(ProductCollection::class);
        $collection->expects(self::once())->method('getItems')->willReturn($products);

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame(['SKU-1', 'SKU-2'], $this->lister->getSkus(3, 2));
    }

    public function testReturnsEmptyArrayWhenFirstPageHasNoItems(): void
    {
        $combine = $this->createMock(Combine::class);
        $combine->expects(self::never())->method('validate');

        $rule = $this->makeRuleMock(3, $combine);
        $this->ruleFactory->expects(self::once())->method('create')->willReturn($rule);

        $collection = $this->createMock(ProductCollection::class);
        $collection->expects(self::once())->method('getItems')->willReturn([]);

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame([], $this->lister->getSkus(3, 4));
    }

    /**
     * A full first page (PAGE_SIZE items) with nothing matching forces a second
     * addFieldToFilter/setCurPage(2) fetch — proves the pagination loop actually advances
     * instead of only ever reading page 1.
     */
    public function testAdvancesToSecondPageWhenFirstPageIsFull(): void
    {
        $combine = $this->createMock(Combine::class);
        $combine->expects(self::exactly(201))->method('validate')->willReturnCallback(
            static fn (Product $product) => $product->getSku() === 'SKU-PAGE2'
        );

        $rule = $this->makeRuleMock(3, $combine);
        $this->ruleFactory->expects(self::once())->method('create')->willReturn($rule);

        $nonMatching = $this->createStub(Product::class);
        $nonMatching->method('getSku')->willReturn('SKU-PAGE1');
        $page1Items = array_fill(0, 200, $nonMatching);

        $page2Product = $this->makeProductMock('SKU-PAGE2');
        $page2Product->expects(self::once())->method('setData')->with('product', $page2Product);

        $page1 = $this->createMock(ProductCollection::class);
        $page1->expects(self::once())->method('setCurPage')->with(1);
        $page1->expects(self::once())->method('getItems')->willReturn($page1Items);

        $page2 = $this->createMock(ProductCollection::class);
        $page2->expects(self::once())->method('setCurPage')->with(2);
        $page2->expects(self::once())->method('getItems')->willReturn([$page2Product]);

        $this->productCollectionFactory->expects(self::exactly(2))->method('create')
            ->willReturnOnConsecutiveCalls($page1, $page2);

        self::assertSame(['SKU-PAGE2'], $this->lister->getSkus(3, 4));
    }

    /**
     * MAX_SCANNED (2000) caps the scan regardless of how many more items a page could still
     * offer — a single oversized "page" of 2005 non-matching items must stop being scanned
     * exactly at item #2000, not fall through to the end of the array.
     */
    public function testStopsScanningOnceMaxScannedIsReached(): void
    {
        $combine = $this->createMock(Combine::class);
        $combine->expects(self::exactly(2000))->method('validate')->willReturn(false);

        $rule = $this->makeRuleMock(3, $combine);
        $this->ruleFactory->expects(self::once())->method('create')->willReturn($rule);

        $product = $this->createMock(Product::class);
        $product->expects(self::exactly(2000))->method('setData')->with('product', $product);

        $collection = $this->createMock(ProductCollection::class);
        $collection->expects(self::once())->method('getItems')->willReturn(array_fill(0, 2005, $product));

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame([], $this->lister->getSkus(3, 999));
    }

    public function testReturnsEmptyArrayWhenNothingMatches(): void
    {
        $combine = $this->createMock(Combine::class);
        $combine->expects(self::once())->method('validate')->willReturn(false);

        $rule = $this->makeRuleMock(3, $combine);
        $this->ruleFactory->expects(self::once())->method('create')->willReturn($rule);

        $product = $this->makeProductMock('SKU-1');
        $product->expects(self::once())->method('setData')->with('product', $product);

        $collection = $this->createMock(ProductCollection::class);
        $collection->expects(self::once())->method('getItems')->willReturn([$product]);

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame([], $this->lister->getSkus(3, 4));
    }
}
