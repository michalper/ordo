<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\PaymentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\ResourceModel\FunnelReport\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for Model\ResourceModel\FunnelReport\Grid\Collection's live sales_order join,
 * drop-off-rate math, and addFieldToFilter() ambiguous-column override - found via the
 * 2026-11-14 full-sweep audit (Test/Mftf/SCENARIOS.md §42): only ever covered by Test/Unit, and
 * the underlying ordo_funnel_daily_stats table's own populator (Cron\CalculateFunnelMetrics)
 * already has Test/Integration/CalculateFunnelMetricsTest.php, but nothing exercises this grid's
 * own code at all.
 *
 * Both the live join (the "date" alias collides between main_table and the live sales_order
 * subquery - exactly what the addFieldToFilter() override exists to resolve) and the ROUND()/
 * NULLIF() drop-off math can only be proven correct against a real query planner and a real
 * division-by-zero guard, not a mocked adapter.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/FunnelReportGridCollectionTest.php
 */
class FunnelReportGridCollectionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var string[] */
    private array $dates = [];

    /** @var int[] */
    private array $orderIds = [];

    /** @var int[] */
    private array $customerIds = [];

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

        $orderResource = self::$objectManager->get(OrderResource::class);
        foreach ($this->orderIds as $orderId) {
            try {
                /** @var Order $order */
                $order = self::$objectManager->get(OrderFactory::class)->create();
                $orderResource->load($order, $orderId);
                if ($order->getId()) {
                    $orderResource->delete($order);
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->orderIds = [];

        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        foreach ($this->customerIds as $customerId) {
            try {
                $customerRepository->deleteById($customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->customerIds = [];

        if ($this->dates !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_funnel_daily_stats'),
                ['date IN (?)' => $this->dates]
            );
        }
        $this->dates = [];
    }

    private function seedDailyStats(
        string $date,
        int $productViewVisitors,
        int $cartAddVisitors,
        int $checkoutStartVisitors
    ): void {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $connection->insert($resourceConnection->getTableName('ordo_funnel_daily_stats'), [
            'date' => $date,
            'product_view_visitors' => $productViewVisitors,
            'cart_add_visitors' => $cartAddVisitors,
            'checkout_start_visitors' => $checkoutStartVisitors,
        ]);
        $this->dates[] = $date;
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-funnel-report-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Funnel');
        $customer->setLastname('Report');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function createOrder(?int $customerId, string $date, string $state = Order::STATE_COMPLETE): int
    {
        /** @var Order $order */
        $order = self::$objectManager->get(OrderFactory::class)->create();
        if ($customerId !== null) {
            $order->setCustomerId($customerId);
        }
        $order->setState($state);
        $order->setStatus($state === Order::STATE_CANCELED ? 'canceled' : 'complete');
        $order->setStoreId(1);
        $order->setGrandTotal(10.0);
        $order->setBaseGrandTotal(10.0);
        $order->setOrderCurrencyCode('USD');
        $order->setBaseCurrencyCode('USD');

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = self::$objectManager->get(PaymentFactory::class)->create();
        $payment->setMethod('checkmo');
        $order->setPayment($payment);

        self::$objectManager->get(OrderResource::class)->save($order);
        $orderId = (int) $order->getId();
        $this->orderIds[] = $orderId;

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->update(
            self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order'),
            ['created_at' => $date . ' 12:00:00'],
            ['entity_id = ?' => $orderId]
        );

        return $orderId;
    }

    private function findRow(array $rows, string $date): ?\Magento\Framework\DataObject
    {
        foreach ($rows as $row) {
            if ($row->getData('date') === $date) {
                return $row;
            }
        }

        return null;
    }

    public function testLiveJoinsDistinctCompletedOrderCustomersAndComputesDropOffRates(): void
    {
        $date = date('Y-m-d', strtotime('-10 days'));
        $this->seedDailyStats($date, productViewVisitors: 10, cartAddVisitors: 4, checkoutStartVisitors: 2);

        // Two orders for the SAME customer on this day must still count as one completed-order
        // visitor (COUNT(DISTINCT customer_id)) - a naive COUNT(*) would double it.
        $repeatCustomerId = $this->createCustomer();
        $this->createOrder($repeatCustomerId, $date);
        $this->createOrder($repeatCustomerId, $date);
        // A canceled order must be excluded from the live join entirely.
        $this->createOrder($this->createCustomer(), $date, Order::STATE_CANCELED);
        // A guest order (no customer_id) must be excluded too - every stage is scoped to
        // customer_id IS NOT NULL (docs/adr/0031).
        $this->createOrder(null, $date);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();
        $row = $this->findRow($rows, $date);

        self::assertNotNull($row);
        self::assertSame(1, (int) $row->getData('completed_order_visitors'));
        self::assertSame(60.0, (float) $row->getData('cart_add_drop_off_rate'));
        self::assertSame(50.0, (float) $row->getData('checkout_start_drop_off_rate'));
        self::assertSame(50.0, (float) $row->getData('completed_order_drop_off_rate'));
    }

    public function testCompletedOrderVisitorsIsZeroNotNullWhenNoOrdersExistThatDay(): void
    {
        $date = date('Y-m-d', strtotime('-11 days'));
        $this->seedDailyStats($date, productViewVisitors: 5, cartAddVisitors: 1, checkoutStartVisitors: 1);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();
        $row = $this->findRow($rows, $date);

        self::assertNotNull($row);
        self::assertSame(0, (int) $row->getData('completed_order_visitors'));
        self::assertSame(
            100.0,
            (float) $row->getData('completed_order_drop_off_rate'),
            'checkout_start_visitors=1 with zero completed orders is a genuine 100% drop at this stage, not an error or a zero.'
        );
    }

    public function testZeroVisitorsAtAStageYieldsANullDropOffRateRatherThanADivisionError(): void
    {
        $date = date('Y-m-d', strtotime('-12 days'));
        $this->seedDailyStats($date, productViewVisitors: 0, cartAddVisitors: 0, checkoutStartVisitors: 0);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();
        $row = $this->findRow($rows, $date);

        self::assertNotNull($row);
        self::assertNull(
            $row->getData('cart_add_drop_off_rate'),
            'NULLIF must guard a zero product_view_visitors denominator - no rate to show, not a 100% drop.'
        );
    }

    public function testDateFieldFilterResolvesTheAmbiguousColumnAgainstMainTableOnly(): void
    {
        $matchingDate = date('Y-m-d', strtotime('-13 days'));
        $otherDate = date('Y-m-d', strtotime('-14 days'));
        $this->seedDailyStats($matchingDate, productViewVisitors: 1, cartAddVisitors: 1, checkoutStartVisitors: 1);
        $this->seedDailyStats($otherDate, productViewVisitors: 1, cartAddVisitors: 1, checkoutStartVisitors: 1);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        // Would throw SQLSTATE 23000 "Column 'date' in where clause is ambiguous" without the
        // addFieldToFilter() override, since the live "orders" join also has its own "date" alias.
        $collection->addFieldToFilter('date', $matchingDate);
        $rows = $collection->getItems();

        self::assertNotNull($this->findRow($rows, $matchingDate));
        self::assertNull($this->findRow($rows, $otherDate));
    }
}
