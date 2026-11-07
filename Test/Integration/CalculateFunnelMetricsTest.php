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
use Ordo\Automation\Cron\CalculateFunnelMetrics;
use Ordo\Automation\Model\ResourceModel\FunnelReport\Grid\Collection as FunnelReportCollection;
use Ordo\Automation\Model\VisitorEventLogger;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for ADR 0031's purchase funnel report - proves both halves a mocked unit
 * test can't: Cron\CalculateFunnelMetrics actually counting real ordo_visitor_event rows into
 * ordo_funnel_daily_stats at the right settled date, and Model\ResourceModel\FunnelReport\Grid\
 * Collection's own live join to a real sales_order correctly producing the completed_order stage
 * and drop-off percentages.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/CalculateFunnelMetricsTest.php
 */
class CalculateFunnelMetricsTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private string $settledDate;

    /** @var int[] */
    private array $customerIds = [];

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

    protected function setUp(): void
    {
        // Same 2-day settling lag Cron\CalculateFunnelMetrics itself applies - this test backdates
        // every fixture to land exactly on the one date the cron will actually process.
        $this->settledDate = date('Y-m-d', (int) strtotime('-2 days'));
    }

    protected function tearDown(): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->delete(
            self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_visitor_event'),
            ['customer_id IN (?)' => $this->customerIds ?: [0]]
        );
        $connection->delete(
            self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_funnel_daily_stats'),
            ['date = ?' => $this->settledDate]
        );

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
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-funnel-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Funnel');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    /**
     * Logs a real ordo_visitor_event row via the real VisitorEventLogger, then backdates it onto
     * $this->settledDate directly (VisitorEventLogger::log() always stamps "now" - same direct-
     * UPDATE technique Test\Mftf\Helper\OrderBackdateHelper already uses for sales_order).
     */
    private function logEventOnSettledDate(string $visitorId, string $eventType, int $customerId): void
    {
        self::$objectManager->get(VisitorEventLogger::class)->log($visitorId, $eventType, null, $customerId);

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $eventTable = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_visitor_event');
        $connection->update(
            $eventTable,
            ['created_at' => $this->settledDate . ' 12:00:00'],
            ['visitor_id = ?' => $visitorId, 'customer_id = ?' => $customerId, 'event_type = ?' => $eventType]
        );
    }

    private function createCompletedOrderOnSettledDate(int $customerId): int
    {
        /** @var Order $order */
        $order = self::$objectManager->get(OrderFactory::class)->create();
        $order->setCustomerId($customerId);
        $order->setState(Order::STATE_COMPLETE);
        $order->setStatus('complete');
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
            ['created_at' => $this->settledDate . ' 12:00:00'],
            ['entity_id = ?' => $orderId]
        );

        return $orderId;
    }

    public function testCronCountsDistinctCustomersPerStageForTheSettledDateOnly(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();

        // Two product_view events for the SAME customer this day - must count as ONE distinct
        // visitor, not two.
        $this->logEventOnSettledDate('v-a-1', 'product_view', $customerA);
        $this->logEventOnSettledDate('v-a-2', 'product_view', $customerA);
        $this->logEventOnSettledDate('v-b', 'product_view', $customerB);
        $this->logEventOnSettledDate('v-a-1', 'cart_add', $customerA);
        $this->logEventOnSettledDate('v-a-1', 'checkout_start', $customerA);

        // A guest event (customer_id null) the same day - must never be counted, see docs/adr/0031.
        self::$objectManager->get(VisitorEventLogger::class)->log('guest-visitor', 'product_view', null, null);

        self::$objectManager->get(CalculateFunnelMetrics::class)->execute();

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_funnel_daily_stats');
        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('date = ?', $this->settledDate)
        );

        self::assertNotFalse($row, 'A row for the settled date must exist after the cron runs.');
        self::assertSame(2, (int) $row['product_view_visitors']);
        self::assertSame(1, (int) $row['cart_add_visitors']);
        self::assertSame(1, (int) $row['checkout_start_visitors']);
    }

    public function testGridCollectionJoinsCompletedOrdersLiveAndComputesDropOffRates(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();

        $this->logEventOnSettledDate('v-a', 'product_view', $customerA);
        $this->logEventOnSettledDate('v-b', 'product_view', $customerB);
        $this->logEventOnSettledDate('v-a', 'cart_add', $customerA);
        $this->logEventOnSettledDate('v-a', 'checkout_start', $customerA);

        self::$objectManager->get(CalculateFunnelMetrics::class)->execute();

        $this->createCompletedOrderOnSettledDate($customerA);

        /** @var FunnelReportCollection $collection */
        $collection = self::$objectManager->create(FunnelReportCollection::class);
        // Same addFieldToFilter() call the grid's own dateRange filter UI makes - proves
        // Collection::addFieldToFilter()'s own "date" qualifying override actually works, not
        // just a hand-qualified WHERE this test could have gotten right by luck.
        $collection->addFieldToFilter('date', $this->settledDate);
        $row = $collection->getFirstItem();

        self::assertSame($this->settledDate, $row->getData('date'));
        self::assertSame(2, (int) $row->getData('product_view_visitors'));
        self::assertSame(1, (int) $row->getData('cart_add_visitors'));
        self::assertSame(1, (int) $row->getData('checkout_start_visitors'));
        self::assertSame(1, (int) $row->getData('completed_order_visitors'));
        // 1 of 2 product-view visitors added to cart -> 50% drop-off.
        self::assertSame('50.0', $row->getData('cart_add_drop_off_rate'));
        // Every cart-adder started checkout -> 0% drop-off.
        self::assertSame('0.0', $row->getData('checkout_start_drop_off_rate'));
        // Every checkout-starter completed an order -> 0% drop-off.
        self::assertSame('0.0', $row->getData('completed_order_drop_off_rate'));
    }
}
