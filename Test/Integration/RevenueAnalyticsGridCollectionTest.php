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
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CouponGenerator;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\RevenueAnalytics\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for the standalone revenue/channel analytics report grid's own backing
 * collection (Model\ResourceModel\RevenueAnalytics\Grid\Collection) - found via the 2026-11-14
 * full-sweep audit (Test/Mftf/SCENARIOS.md §42): zero Integration/MFTF coverage, only a plain
 * Test/Unit on the read-only controller action.
 *
 * The collection's own docblock makes several real, DB-backed claims a mocked adapter can't
 * verify: canceled orders are excluded entirely, "new customer" means this is the customer's own
 * earliest non-canceled order (guest orders always count as new), campaign_name picks the lowest
 * campaign_id when ordo_campaign_outcome_log has more than one row for an order, and
 * coupon_campaign_name is a SEPARATE attribution from campaign_name that can legitimately differ
 * for the very same order. This suite proves all four against a real database, same "no backing
 * table of its own, everything built in _initSelect()" shape Test/Integration/
 * CohortRetentionGridCollectionTest.php and Test/Integration/CampaignComparisonGridCollectionTest.php
 * already established a precedent for.
 *
 * Orders are real (Order model + OrderResource, no real checkout - same reasoning
 * CohortRetentionGridCollectionTest already gives: this collection only reads order-level columns,
 * none of which need a real cart/product/checkout flow). The billing address, outcome-log,
 * coupon and coupon-campaign-log rows are inserted directly for the same reason
 * CampaignComparisonGridCollectionTest's own logOutcome()/attributeRevenue() give: minimal,
 * safe fixtures for tables this collection only ever reads.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/RevenueAnalyticsGridCollectionTest.php
 */
class RevenueAnalyticsGridCollectionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $orderIds = [];

    /** @var int[] */
    private array $customerIds = [];

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $ruleIds = [];

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

        if ($this->orderIds !== []) {
            $connection->delete(
                $resourceConnection->getTableName('sales_order_address'),
                ['parent_id IN (?)' => $this->orderIds]
            );
            $connection->delete(
                $resourceConnection->getTableName('ordo_campaign_outcome_log'),
                ['order_id IN (?)' => $this->orderIds]
            );
        }

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

        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        foreach ($this->campaignIds as $campaignId) {
            try {
                /** @var Campaign $campaign */
                $campaign = $campaignFactory->create();
                $campaignResource->load($campaign, $campaignId);
                if ($campaign->getEntityId()) {
                    $campaignResource->delete($campaign);
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->campaignIds = [];

        $ruleResource = self::$objectManager->get(RuleResource::class);
        $ruleFactory = self::$objectManager->get(RuleFactory::class);
        foreach ($this->ruleIds as $ruleId) {
            try {
                $rule = $ruleFactory->create();
                $ruleResource->load($rule, $ruleId);
                if ($rule->getRuleId()) {
                    $ruleResource->delete($rule);
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->ruleIds = [];

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

    // --- fixture builders ------------------------------------------------------------------

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-revenue-analytics-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Revenue');
        $customer->setLastname('Analytics');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function createOrder(?int $customerId, float $grandTotal = 10.0, ?string $couponCode = null): int
    {
        /** @var Order $order */
        $order = self::$objectManager->get(OrderFactory::class)->create();
        if ($customerId !== null) {
            $order->setCustomerId($customerId);
        }
        $order->setState(Order::STATE_COMPLETE);
        $order->setStatus('complete');
        $order->setStoreId(1);
        $order->setGrandTotal($grandTotal);
        $order->setBaseGrandTotal($grandTotal);
        $order->setOrderCurrencyCode('USD');
        $order->setBaseCurrencyCode('USD');
        if ($couponCode !== null) {
            $order->setCouponCode($couponCode);
        }

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = self::$objectManager->get(PaymentFactory::class)->create();
        $payment->setMethod('checkmo');
        $order->setPayment($payment);

        self::$objectManager->get(OrderResource::class)->save($order);
        $orderId = (int) $order->getId();
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function cancelOrder(int $orderId): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->update(
            self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order'),
            ['state' => Order::STATE_CANCELED, 'status' => 'canceled'],
            ['entity_id = ?' => $orderId]
        );
    }

    private function setBillingAddress(int $orderId, string $countryId, string $city): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->insert(
            self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order_address'),
            [
                'parent_id' => $orderId,
                'address_type' => 'billing',
                'country_id' => $countryId,
                'city' => $city,
            ]
        );
    }

    private function createCampaign(): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Revenue analytics grid test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);

        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        return $campaignId;
    }

    private function logOutcome(int $orderId, int $campaignId, int $customerId): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $connection->insert($resourceConnection->getTableName('ordo_campaign_outcome_log'), [
            'campaign_id' => $campaignId,
            'customer_id' => $customerId,
            'sent_at' => date('Y-m-d H:i:s'),
            'acted_at' => date('Y-m-d H:i:s'),
            'order_id' => $orderId,
        ]);
    }

    private function createRule(): int
    {
        $ruleFactory = self::$objectManager->get(RuleFactory::class);
        $ruleResource = self::$objectManager->get(RuleResource::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $rule = $ruleFactory->create();
        $rule->setName('Revenue analytics grid test rule ' . uniqid('', true));
        $rule->setWebsiteIds([(int) $storeManager->getWebsite()->getId()]);
        $rule->setCustomerGroupIds([0, 1, 2, 3]);
        $rule->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC);
        $rule->setSimpleAction('by_percent');
        $rule->setDiscountAmount(10);
        $rule->setIsActive(true);
        $rule->setFromDate(date('Y-m-d'));
        $ruleResource->save($rule);

        $ruleId = (int) $rule->getRuleId();
        $this->ruleIds[] = $ruleId;

        return $ruleId;
    }

    private function findRow(array $rows, int $orderId): ?\Magento\Framework\DataObject
    {
        foreach ($rows as $row) {
            if ((int) $row->getData('entity_id') === $orderId) {
                return $row;
            }
        }

        return null;
    }

    // --- tests -------------------------------------------------------------------------------

    public function testExcludesCanceledOrdersEntirely(): void
    {
        $liveOrderId = $this->createOrder(null);
        $canceledOrderId = $this->createOrder(null);
        $this->cancelOrder($canceledOrderId);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertNotNull($this->findRow($rows, $liveOrderId), 'A non-canceled order must appear in the grid.');
        self::assertNull($this->findRow($rows, $canceledOrderId), 'A canceled order must never appear in the grid.');
    }

    public function testFlagsOnlyACustomersOwnEarliestOrderAsNewAndGuestOrdersAlwaysAsNew(): void
    {
        $customerId = $this->createCustomer();
        $firstOrderId = $this->createOrder($customerId);
        $secondOrderId = $this->createOrder($customerId);
        $guestOrderId = $this->createOrder(null);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertSame('1', (string) $this->findRow($rows, $firstOrderId)->getData('is_new_customer'));
        self::assertSame('0', (string) $this->findRow($rows, $secondOrderId)->getData('is_new_customer'));
        self::assertSame('1', (string) $this->findRow($rows, $guestOrderId)->getData('is_new_customer'));
    }

    public function testCampaignNamePicksTheLowestCampaignIdWhenAnOrderHasMultipleOutcomeRows(): void
    {
        $customerId = $this->createCustomer();
        $orderId = $this->createOrder($customerId);

        $higherCampaignId = $this->createCampaign();
        $lowerCampaignId = $this->createCampaign();
        // Insert the higher id first so a naive "last join wins" bug would be masked by
        // insertion order rather than genuinely proven by MIN(campaign_id).
        $firstInsertedCampaignId = max($higherCampaignId, $lowerCampaignId);
        $secondInsertedCampaignId = min($higherCampaignId, $lowerCampaignId);
        $this->logOutcome($orderId, $firstInsertedCampaignId, $customerId);
        $this->logOutcome($orderId, $secondInsertedCampaignId, $customerId);

        $expectedCampaignName = self::$objectManager->get(CampaignFactory::class)->create();
        self::$objectManager->get(CampaignResource::class)->load($expectedCampaignName, $secondInsertedCampaignId);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertSame(
            $expectedCampaignName->getName(),
            $this->findRow($rows, $orderId)->getData('campaign_name'),
            'campaign_name must come from the lowest campaign_id among this order\'s outcome-log rows.'
        );
    }

    public function testOrganicOrderWithNoOutcomeLogRowShowsANullCampaignName(): void
    {
        $orderId = $this->createOrder(null);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();

        self::assertNull(
            $this->findRow($rows, $orderId)->getData('campaign_name'),
            'An order with no ordo_campaign_outcome_log row is organic revenue, not an error - campaign_name must be null, not a fallback/empty-string value.'
        );
    }

    public function testCouponCampaignNameCanDifferFromTheOrdersOwnOutcomeAttributedCampaignName(): void
    {
        $customerId = $this->createCustomer();

        $outcomeCampaignId = $this->createCampaign();
        $couponCampaignId = $this->createCampaign();

        $ruleId = $this->createRule();
        /** @var CouponGenerator $couponGenerator */
        $couponGenerator = self::$objectManager->get(CouponGenerator::class);
        $couponCode = $couponGenerator->generate($ruleId, 'REVTEST', 1, $couponCampaignId);

        $orderId = $this->createOrder($customerId, 10.0, $couponCode);
        // This order's own outcome-log attribution points at a DIFFERENT campaign than the one
        // whose generate_coupon action minted the coupon it redeemed - proving the two columns
        // are genuinely independent, not aliases of the same join.
        $this->logOutcome($orderId, $outcomeCampaignId, $customerId);

        $outcomeCampaign = self::$objectManager->get(CampaignFactory::class)->create();
        self::$objectManager->get(CampaignResource::class)->load($outcomeCampaign, $outcomeCampaignId);
        $couponCampaign = self::$objectManager->get(CampaignFactory::class)->create();
        self::$objectManager->get(CampaignResource::class)->load($couponCampaign, $couponCampaignId);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();
        $row = $this->findRow($rows, $orderId);

        self::assertSame($outcomeCampaign->getName(), $row->getData('campaign_name'));
        self::assertSame($couponCampaign->getName(), $row->getData('coupon_campaign_name'));
        self::assertNotSame(
            $row->getData('campaign_name'),
            $row->getData('coupon_campaign_name'),
            'campaign_name and coupon_campaign_name must be free to disagree for the same order.'
        );
    }

    public function testJoinsBillingAddressCountryAndCity(): void
    {
        $orderId = $this->createOrder(null);
        $this->setBillingAddress($orderId, 'PL', 'Warsaw');

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $rows = $collection->getItems();
        $row = $this->findRow($rows, $orderId);

        self::assertSame('PL', $row->getData('country_id'));
        self::assertSame('Warsaw', $row->getData('city'));
    }
}
