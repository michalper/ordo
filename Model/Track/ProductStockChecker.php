<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Track;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Live stock-quantity lookup for Controller\Track\LowStock, same "ask the real Magento API, no
 * new table" shape as Cron\ScanBackInStockAlerts' own $product->isSalable() check - this just
 * needs the actual qty, not only a salable boolean, so it goes through StockRegistryInterface
 * rather than loading a full product. No MSI/multi-source awareness: this module doesn't use MSI
 * anywhere else (ScanBackInStockAlerts included), and StockRegistryInterface already returns the
 * right single-source-compatible qty either way.
 */
class ProductStockChecker
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    public function getQty(int $productId): ?float
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $stockItem->getItemId() !== null ? (float) $stockItem->getQty() : null;
    }
}
