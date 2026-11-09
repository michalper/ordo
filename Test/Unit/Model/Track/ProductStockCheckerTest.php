<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Track;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\Track\ProductStockChecker;
use PHPUnit\Framework\TestCase;

class ProductStockCheckerTest extends TestCase
{
    public function testGetQtyReturnsTheRealStockQuantity(): void
    {
        $stockItem = $this->createStub(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(99);
        $stockItem->method('getQty')->willReturn(3.0);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->expects(self::once())->method('getStockItem')->with(42)->willReturn($stockItem);

        $checker = new ProductStockChecker($stockRegistry);

        self::assertSame(3.0, $checker->getQty(42));
    }

    public function testGetQtyReturnsNullWhenProductHasNoStockItem(): void
    {
        $stockItem = $this->createStub(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(null);

        $stockRegistry = $this->createStub(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $checker = new ProductStockChecker($stockRegistry);

        self::assertNull($checker->getQty(42));
    }

    public function testGetQtyReturnsNullWhenProductDoesNotExist(): void
    {
        $stockRegistry = $this->createStub(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willThrowException(new NoSuchEntityException());

        $checker = new ProductStockChecker($stockRegistry);

        self::assertNull($checker->getQty(999));
    }
}
