<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\PaymentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\ResourceModel\Rfm\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for the standalone RFM report grid's own backing collection
 * (Model\ResourceModel\Rfm\Grid\Collection) - only ever unit-tested against a mocked connection
 * (Test/Unit/Model/ResourceModel/Rfm/Grid/CollectionTest.php), which proves the SQL fragments get
 * built, not that the real customer_entity/sales_order join and NTILE(5) window functions
 * actually execute against a real MySQL 8 server.
 *
 * Added specifically to prove the rfm_score refactor found during a full-repo /simplify sweep:
 * rfm_score used to repeat the same three NTILE(5) window specs inline (since one select-list
 * expression can't reference another window-function alias in the same SELECT), doubling the
 * sort/partition cost of the whole join. It's now computed in an outer wrapper select from the
 * already-materialized monetary_quintile/frequency_quintile/recency_quintile columns of an inner
 * derived table instead - this test's own core assertion is that rfm_score's three digits still
 * exactly match those three columns on the SAME row, proving the wrapper produces identical
 * results to the old inline-repeated version, not just that it executes without a SQL error.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/RfmGridCollectionTest.php
 */
class RfmGridCollectionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

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

    protected function tearDown(): void
    {
        $orderResource = self::$objectManager->get(OrderResource::class);
        foreach ($this->orderIds as $orderId) {
            try {
                /** @var Order $order */
                $order = self::$objectManager->get(OrderFactory::class)->create();
                $orderResource->load($order, $orderId);
                if ($order->getId()) {
                    $orderResource->delete($order);
                }
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }
        $this->orderIds = [];

        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        foreach ($this->customerIds as $customerId) {
            try {
                $customerRepository->deleteById($customerId);
            } catch (\Throwable) {
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

        $email = 'ordo-rfm-grid-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('RfmGrid');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    /**
     * $grandTotal and $baseGrandTotal default to the same value but can be given separately -
     * deliberately different in testFrequencyAndMonetarySumRealOrdersForACustomerWithPurchases()
     * below, proving the grid's own "monetary" column sums base_grand_total (the field that
     * stays meaningful across orders placed in different currencies), not grand_total.
     */
    private function createCompletedOrder(int $customerId, float $grandTotal, ?float $baseGrandTotal = null): int
    {
        /** @var Order $order */
        $order = self::$objectManager->get(OrderFactory::class)->create();
        $order->setCustomerId($customerId);
        $order->setState(Order::STATE_COMPLETE);
        $order->setStatus('complete');
        $order->setStoreId(1);
        $order->setGrandTotal($grandTotal);
        $order->setBaseGrandTotal($baseGrandTotal ?? $grandTotal);
        $order->setOrderCurrencyCode('USD');
        $order->setBaseCurrencyCode('USD');

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = self::$objectManager->get(PaymentFactory::class)->create();
        $payment->setMethod('checkmo');
        $order->setPayment($payment);

        self::$objectManager->get(OrderResource::class)->save($order);
        $orderId = (int) $order->getId();
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function findRow(array $rows, int $customerId): ?DataObject
    {
        foreach ($rows as $row) {
            if ((int) $row->getData('entity_id') === $customerId) {
                return $row;
            }
        }

        return null;
    }

    public function testFrequencyAndMonetarySumRealOrdersForACustomerWithPurchases(): void
    {
        $customerId = $this->createCustomer();
        // grandTotal/baseGrandTotal deliberately differ - proves "monetary" sums
        // base_grand_total (35.0 + 55.0 = 90.0), not grand_total (40.0 + 60.0 = 100.0).
        $this->createCompletedOrder($customerId, grandTotal: 40.0, baseGrandTotal: 35.0);
        $this->createCompletedOrder($customerId, grandTotal: 60.0, baseGrandTotal: 55.0);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();
        $row = $this->findRow($rows, $customerId);

        self::assertNotNull($row, 'The customer must appear in the RFM grid even on their first load.');
        self::assertSame(2, (int) $row->getData('frequency'));
        self::assertEqualsWithDelta(90.0, (float) $row->getData('monetary'), 0.0001);
        self::assertNotNull($row->getData('last_order_at'));
        self::assertLessThan(999999, (int) $row->getData('recency_days'));
    }

    public function testDefaultsToZeroFrequencyAndSentinelRecencyForACustomerWithNoOrders(): void
    {
        $customerId = $this->createCustomer();

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();
        $row = $this->findRow($rows, $customerId);

        self::assertNotNull($row);
        self::assertSame(0, (int) $row->getData('frequency'));
        self::assertEqualsWithDelta(0.0, (float) $row->getData('monetary'), 0.0001);
        self::assertNull($row->getData('last_order_at'));
        // recency_days itself is the plain DATEDIFF, null for a no-order customer - the 999999
        // sentinel only feeds the recency_quintile ranking expression (see
        // Collection::_initSelect()'s own $recencyForRanking), not this display column.
        self::assertNull($row->getData('recency_days'));
        // A hard-coded bucket number would be flaky here - NTILE's exact bucket boundaries
        // depend on every OTHER customer in this shared sandbox DB, not just this fixture. The
        // real, environment-independent guarantee is just that a no-order customer still gets a
        // real quintile value (1-5), not null/excluded.
        self::assertMatchesRegularExpression('/^[1-5]$/', (string) $row->getData('recency_quintile'));
    }

    /**
     * The core regression this test exists for: rfm_score is now computed in an outer wrapper
     * select from the inner select's own already-materialized quintile columns, instead of
     * repeating the three NTILE(5) window specs inline a second time. This proves that wrapper
     * produces exactly the same three digits as the separately-selected quintile columns for the
     * same row - not just that the query executes.
     */
    public function testRfmScoreExactlyMatchesTheConcatenationOfItsThreeQuintileColumns(): void
    {
        $firstCustomerId = $this->createCustomer();
        $this->createCompletedOrder($firstCustomerId, 25.0);

        $secondCustomerId = $this->createCustomer();
        $this->createCompletedOrder($secondCustomerId, 500.0);
        $this->createCompletedOrder($secondCustomerId, 500.0);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        foreach ([$firstCustomerId, $secondCustomerId] as $customerId) {
            $row = $this->findRow($rows, $customerId);
            self::assertNotNull($row);

            $expectedScore = $row->getData('recency_quintile')
                . $row->getData('frequency_quintile')
                . $row->getData('monetary_quintile');

            self::assertSame(
                $expectedScore,
                (string) $row->getData('rfm_score'),
                'rfm_score must exactly match recency+frequency+monetary quintile for this same row.'
            );
            self::assertMatchesRegularExpression('/^[1-5]{3}$/', (string) $row->getData('rfm_score'));
        }
    }
}
