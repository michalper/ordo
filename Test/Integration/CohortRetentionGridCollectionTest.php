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
use Ordo\Automation\Model\ResourceModel\CohortRetention\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for ADR 0029's acquisition-campaign narrowing - Collection's own SQL
 * (a derived-table pipeline rebuilt entirely inside addFieldToFilter(), with a MySQL
 * ROW_NUMBER() window function picking one campaign per order) is exactly the kind of thing a
 * unit test with a mocked adapter can't meaningfully verify; this proves it against a real
 * database instead.
 *
 * Orders are created directly via Order model + OrderResource (no real checkout) - this
 * collection only reads customer_id/created_at/state off sales_order, none of which need a real
 * cart/product/checkout flow, and this module's established OrderBackdateHelper (MFTF) already
 * treats a direct write to sales_order as safe for exactly this kind of test setup.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/CohortRetentionGridCollectionTest.php
 */
class CohortRetentionGridCollectionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $customerIds = [];

    /** @var int[] */
    private array $orderIds = [];

    /** @var int[] */
    private array $attributionIds = [];

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
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();

        foreach ($this->attributionIds as $id) {
            $connection->delete(
                self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_campaign_attribution'),
                ['entity_id = ?' => $id]
            );
        }
        $this->attributionIds = [];

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

        $email = 'ordo-cohort-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Cohort');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    /**
     * $monthsAgo backdates created_at (via direct UPDATE, same reasoning as OrderBackdateHelper)
     * so cohort_month/months_since_first_purchase land on predictable, distinct months instead of
     * depending on when the test happens to run.
     */
    private function createOrder(int $customerId, int $monthsAgo): int
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
            ['created_at' => date('Y-m-d H:i:s', (int) strtotime("-{$monthsAgo} months"))],
            ['entity_id = ?' => $orderId]
        );

        return $orderId;
    }

    private function attributeOrderToCampaign(int $orderId, int $customerId, int $campaignId, float $revenue): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_campaign_attribution');

        $connection->insert($table, [
            'order_id' => $orderId,
            'campaign_id' => $campaignId,
            'customer_id' => $customerId,
            'attributed_revenue' => $revenue,
            'touch_count' => 1,
        ]);

        $this->attributionIds[] = (int) $connection->lastInsertId($table);
    }

    public function testUnfilteredOutputIsUnchangedByTheNewFilterCapability(): void
    {
        $customerId = $this->createCustomer();
        $firstOrderId = $this->createOrder($customerId, monthsAgo: 2);
        $this->createOrder($customerId, monthsAgo: 0);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        $cohortMonth = date('Y-m-01', (int) strtotime('-2 months'));
        $matching = array_filter($rows, fn ($row) => $row->getData('cohort_month') === $cohortMonth);

        self::assertNotEmpty($matching, 'Unfiltered collection must still include this cohort month.');
    }

    public function testAcquisitionCampaignFilterNarrowsCohortSizeToAttributedCustomersOnly(): void
    {
        $campaignId = 777001;
        $otherCampaignId = 777002;

        $attributedCustomerId = $this->createCustomer();
        $unattributedCustomerId = $this->createCustomer();

        $attributedFirstOrderId = $this->createOrder($attributedCustomerId, monthsAgo: 3);
        $this->createOrder($attributedCustomerId, monthsAgo: 0);
        $this->attributeOrderToCampaign($attributedFirstOrderId, $attributedCustomerId, $campaignId, 50.0);

        $unattributedFirstOrderId = $this->createOrder($unattributedCustomerId, monthsAgo: 3);
        // A real customer whose first order is attributed to a DIFFERENT campaign - proves the
        // filter actually narrows (excludes this customer), not just "any row exists at all".
        $this->attributeOrderToCampaign($unattributedFirstOrderId, $unattributedCustomerId, $otherCampaignId, 30.0);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $collection->addFieldToFilter('acquisition_campaign_id', $campaignId);
        $rows = $collection->getItems();

        $cohortMonth = date('Y-m-01', (int) strtotime('-3 months'));
        $matching = array_values(array_filter($rows, fn ($row) => $row->getData('cohort_month') === $cohortMonth));

        self::assertNotEmpty($matching, 'Filtered collection must include the campaign-attributed cohort month.');
        self::assertSame(
            1,
            (int) $matching[0]->getData('cohort_size'),
            'cohort_size must reflect only the customer attributed to the filtered campaign, not every customer in that cohort month.'
        );
    }

    /**
     * ordo_campaign_attribution is multi-touch - ROW_NUMBER() must pick exactly the
     * highest-attributed_revenue campaign for a given order, not both, or this customer would be
     * double-counted into two different campaigns' cohorts.
     */
    public function testMultiTouchAttributionCreditsOnlyTheHighestRevenueCampaign(): void
    {
        $primaryCampaignId = 777003;
        $secondaryCampaignId = 777004;
        $customerId = $this->createCustomer();

        $firstOrderId = $this->createOrder($customerId, monthsAgo: 4);
        $this->attributeOrderToCampaign($firstOrderId, $customerId, $secondaryCampaignId, 10.0);
        $this->attributeOrderToCampaign($firstOrderId, $customerId, $primaryCampaignId, 90.0);

        /** @var Collection $primaryCollection */
        $primaryCollection = self::$objectManager->create(Collection::class);
        $primaryCollection->addFieldToFilter('acquisition_campaign_id', $primaryCampaignId);
        $primaryRows = $primaryCollection->getItems();

        /** @var Collection $secondaryCollection */
        $secondaryCollection = self::$objectManager->create(Collection::class);
        $secondaryCollection->addFieldToFilter('acquisition_campaign_id', $secondaryCampaignId);
        $secondaryRows = $secondaryCollection->getItems();

        $cohortMonth = date('Y-m-01', (int) strtotime('-4 months'));
        $primaryMatch = array_filter($primaryRows, fn ($row) => $row->getData('cohort_month') === $cohortMonth);
        $secondaryMatch = array_filter($secondaryRows, fn ($row) => $row->getData('cohort_month') === $cohortMonth);

        self::assertNotEmpty($primaryMatch, 'The higher-revenue campaign must get credit for this customer.');
        self::assertEmpty($secondaryMatch, 'The lower-revenue campaign must NOT also get credit for the same customer.');
    }
}
