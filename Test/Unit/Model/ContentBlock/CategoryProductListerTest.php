<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Ordo\Automation\Model\ContentBlock\CategoryProductLister;
use PHPUnit\Framework\TestCase;

class CategoryProductListerTest extends TestCase
{
    private ProductCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $productCollectionFactory;
    private CategoryProductLister $lister;

    protected function setUp(): void
    {
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->lister = new CategoryProductLister($this->productCollectionFactory);
    }

    private function makeProductStub(string $sku): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }

    public function testReturnsSkusFromCollectionScopedToCategory(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->expects(self::once())->method('addAttributeToSelect')->with('sku');
        $collection->expects(self::once())->method('addCategoriesFilter')->with(['eq' => [15]]);
        $collection->expects(self::once())->method('setPageSize')->with(4);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->makeProductStub('SKU-1'),
            $this->makeProductStub('SKU-2'),
        ]));

        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame(['SKU-1', 'SKU-2'], $this->lister->getSkus(15, 4));
    }

    public function testReturnsEmptyArrayWhenCategoryIdIsZeroOrNegative(): void
    {
        $this->productCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->lister->getSkus(0, 4));
        self::assertSame([], $this->lister->getSkus(-1, 4));
    }

    public function testReturnsEmptyArrayWhenLimitIsZeroOrNegative(): void
    {
        $this->productCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->lister->getSkus(15, 0));
        self::assertSame([], $this->lister->getSkus(15, -1));
    }

    public function testReturnsEmptyArrayWhenCollectionHasNoProducts(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->expects(self::once())->method('addAttributeToSelect')->with('sku');
        $collection->expects(self::once())->method('addCategoriesFilter')->with(['eq' => [15]]);
        $collection->expects(self::once())->method('setPageSize')->with(4);
        $collection->expects(self::once())->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->productCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame([], $this->lister->getSkus(15, 4));
    }
}
