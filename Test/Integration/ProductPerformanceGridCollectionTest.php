<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\ResourceModel\ProductPerformance\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for the standalone product performance report grid's own backing collection
 * (Model\ResourceModel\ProductPerformance\Grid\Collection) - found via the 2026-11-14 full-sweep
 * audit (Test/Mftf/SCENARIOS.md §42): only ever unit-tested against a mocked connection
 * (Test/Unit/Model/ResourceModel/ProductPerformance/Grid/CollectionTest.php), which proves the
 * SQL fragments get built, not that the real sales_order/sales_order_item subselect actually sums
 * qty_ordered per SKU, excludes canceled orders, or that the NULLIF-guarded abandon_rate reads as
 * null (not a false 100%) for a never-viewed product. Same "no backing table of its own,
 * everything built in _initSelect()" shape RevenueAnalyticsGridCollectionTest/
 * CohortRetentionGridCollectionTest/CampaignComparisonGridCollectionTest already established a
 * precedent for.
 *
 * The collection's main table is catalog_product_entity with no WHERE clause of its own, so
 * getItems() returns every product in the catalog, not just this test's fixtures - like those
 * sibling tests, this one locates its own rows via findRow() rather than asserting on the full
 * result set or getSize().
 *
 * Products are real (ProductRepositoryInterface::save()/deleteById(), same pattern
 * FreeGiftManagementScenarioTest already uses) since the collection's main table is
 * catalog_product_entity itself. Performance/order rows are inserted directly via
 * ResourceConnection, same reasoning CalculateProductPerformanceTest and
 * SendWishlistAbandonmentRemindersTest's own createOrderWithItem() already give: minimal, safe
 * fixtures for tables this collection only ever reads.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/ProductPerformanceGridCollectionTest.php
 */
class ProductPerformanceGridCollectionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var string[] */
    private array $skus = [];

    /** @var int[] */
    private array $orderIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $orderItemTable = $resourceConnection->getTableName('sales_order_item');
        $orderTable = $resourceConnection->getTableName('sales_order');
        foreach ($this->orderIds as $orderId) {
            $connection->delete($orderItemTable, ['order_id = ?' => $orderId]);
            $connection->delete($orderTable, ['entity_id = ?' => $orderId]);
        }
        $this->orderIds = [];

        if ($this->skus !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_product_performance'),
                ['sku IN (?)' => $this->skus]
            );
        }

        $productRepository = self::$objectManager->get(ProductRepositoryInterface::class);
        foreach ($this->skus as $sku) {
            try {
                $productRepository->deleteById($sku);
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }
        $this->skus = [];
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createSimpleProduct(string $sku): ProductInterface
    {
        $this->skus[] = $sku;

        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        /** @var ProductInterface $product */
        $product = self::$objectManager->get(ProductInterfaceFactory::class)->create();
        $product->setTypeId(Type::TYPE_SIMPLE);
        $product->setAttributeSetId(4);
        $product->setSku($sku);
        $product->setName('Ordo Product Performance Test Product ' . $sku);
        $product->setPrice(10.0);
        $product->setWebsiteIds([(int) $storeManager->getWebsite()->getId()]);
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

        return self::$objectManager->get(ProductRepositoryInterface::class)->save($product);
    }

    private function setViewCount(string $sku, int $viewCount): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $connection->insert($resourceConnection->getTableName('ordo_product_performance'), [
            'sku' => $sku,
            'view_count' => $viewCount,
        ]);
    }

    private function createOrderWithItem(string $sku, int $qtyOrdered, string $state = 'complete'): int
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $orderTable = $resourceConnection->getTableName('sales_order');
        $connection->insert($orderTable, [
            'grand_total' => 10.0 * $qtyOrdered,
            'state' => $state,
            'status' => $state,
            'store_id' => $storeId,
            'created_at' => date('Y-m-d H:i:s'),
            'increment_id' => 'ORDOPPTEST-' . uniqid('', true),
        ]);
        $orderId = (int) $connection->lastInsertId($orderTable);
        $this->orderIds[] = $orderId;

        $orderItemTable = $resourceConnection->getTableName('sales_order_item');
        $connection->insert($orderItemTable, [
            'order_id' => $orderId,
            'sku' => $sku,
            'qty_ordered' => $qtyOrdered,
            'price' => 10.0,
        ]);

        return $orderId;
    }

    private function findRow(array $rows, string $sku): ?DataObject
    {
        foreach ($rows as $row) {
            if ($row->getData('sku') === $sku) {
                return $row;
            }
        }

        return null;
    }

    // --- tests -------------------------------------------------------------------------------

    public function testViewCountComesFromTheMaterializedPerformanceTableAndDefaultsToZeroWithoutIt(): void
    {
        $viewedSku = 'ordo-ppt-viewed-' . uniqid('', true);
        $neverViewedSku = 'ordo-ppt-unviewed-' . uniqid('', true);
        $this->createSimpleProduct($viewedSku);
        $this->createSimpleProduct($neverViewedSku);
        $this->setViewCount($viewedSku, 42);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertSame(42, (int) $this->findRow($rows, $viewedSku)->getData('view_count'));
        self::assertSame(
            0,
            (int) $this->findRow($rows, $neverViewedSku)->getData('view_count'),
            'A product with no ordo_product_performance row must read as 0 views, not null/missing.'
        );
    }

    public function testPurchaseCountSumsQtyOrderedAcrossMultipleRealOrdersForTheSameSku(): void
    {
        $sku = 'ordo-ppt-purchased-' . uniqid('', true);
        $this->createSimpleProduct($sku);
        $this->createOrderWithItem($sku, 2);
        $this->createOrderWithItem($sku, 3);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertSame(
            5,
            (int) $this->findRow($rows, $sku)->getData('purchase_count'),
            'purchase_count must be the real SUM(qty_ordered) across every non-canceled order for this sku.'
        );
    }

    public function testExcludesCanceledOrdersFromThePurchaseCount(): void
    {
        $sku = 'ordo-ppt-canceled-' . uniqid('', true);
        $this->createSimpleProduct($sku);
        $this->createOrderWithItem($sku, 4, 'complete');
        $this->createOrderWithItem($sku, 99, 'canceled');

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertSame(
            4,
            (int) $this->findRow($rows, $sku)->getData('purchase_count'),
            'A canceled order\'s qty_ordered must never be counted towards purchase_count.'
        );
    }

    public function testDefaultsToZeroPurchaseCountForAProductWithNoOrders(): void
    {
        $sku = 'ordo-ppt-neverordered-' . uniqid('', true);
        $this->createSimpleProduct($sku);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertSame(0, (int) $this->findRow($rows, $sku)->getData('purchase_count'));
    }

    public function testAbandonRateIsComputedFromRealViewAndPurchaseCounts(): void
    {
        $sku = 'ordo-ppt-abandon-' . uniqid('', true);
        $this->createSimpleProduct($sku);
        $this->setViewCount($sku, 10);
        $this->createOrderWithItem($sku, 4);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        // 1 - purchases/views = 1 - 4/10 = 0.6.
        self::assertEqualsWithDelta(
            0.6,
            (float) $this->findRow($rows, $sku)->getData('abandon_rate'),
            0.0001
        );
    }

    public function testAbandonRateIsNullRatherThanAFalseHundredPercentWhenViewCountIsZero(): void
    {
        $sku = 'ordo-ppt-neverviewed-' . uniqid('', true);
        $this->createSimpleProduct($sku);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertNull(
            $this->findRow($rows, $sku)->getData('abandon_rate'),
            'With zero views the NULLIF guard must make abandon_rate null (no data), not a misleading 100% abandon rate.'
        );
    }
}
