<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\PaymentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/database coverage for the `churn_risk_at_least` campaign condition
 * (Model\Campaign\Condition\ChurnRiskAtLeast / Model\ChurnRisk\ChurnRiskCalculator) - found via a
 * full-sweep audit against etc/di.xml's ConditionPool with ZERO Test/Mftf/SCENARIOS.md footprint
 * and ZERO Integration/MFTF coverage, only Test/Unit (mocked ResourceConnection/DateTime, which
 * can't prove the real median-interval SQL/arithmetic actually works against a real
 * sales_order table).
 *
 * No dedicated admin-visible widget beyond the generic numeric "threshold" field the Flow canvas
 * already renders for every condition (same reasoning this file's own ConditionPool siblings
 * loyalty_tier_at_least/survey_response_equals already give for staying Integration-only, not
 * MFTF) - what's genuinely unverified is whether a real customer's real non-canceled order
 * history, fed through CampaignDispatcher's real ConditionPool, produces the correct
 * satisfied/not-satisfied decision end to end.
 *
 * Orders are created directly via Order model + OrderResource with a backdated created_at (same
 * technique Test/Integration/CohortRetentionGridCollectionTest.php already establishes) - this
 * condition only reads customer_id/created_at/state off sales_order, no real checkout needed.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes every
 * campaign/customer/order/tag this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/ChurnRiskAtLeastConditionScenarioTest.php
 */
class ChurnRiskAtLeastConditionScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $customerIds = [];

    /** @var int[] */
    private array $orderIds = [];

    /** @var array{customerId: int, tag: string}[] */
    private array $tagsToClean = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);
    }

    protected function setUp(): void
    {
        $this->dispatcher = self::$objectManager->get(CampaignDispatcher::class);
        $this->cache = self::$objectManager->get(CacheInterface::class);
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tagsToClean as $entry) {
            $this->tagManager->removeTag($entry['customerId'], $entry['tag']);
        }
        $this->tagsToClean = [];

        foreach ($this->campaignIds as $campaignId) {
            $this->deleteCampaign($campaignId);
        }
        $this->campaignIds = [];

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

    // --- fixture builders ------------------------------------------------------------------

    private function createCampaign(string $triggerEvent, float $ratioAtLeast): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Churn risk condition test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData(['campaign_id' => $campaignId, 'trigger_event' => $triggerEvent]);
        $triggerResource->save($trigger);

        $conditionFactory = self::$objectManager->get(CampaignConditionFactory::class);
        $conditionResource = self::$objectManager->get(CampaignConditionResource::class);
        /** @var CampaignCondition $condition */
        $condition = $conditionFactory->create();
        $condition->setData([
            'campaign_id' => $campaignId,
            'type' => 'churn_risk_at_least',
            'params' => json_encode(['churn_risk_ratio_at_least' => $ratioAtLeast]),
            'sort_order' => 0,
        ]);
        $conditionResource->save($condition);

        return $campaignId;
    }

    private function addAction(int $campaignId, string $type, array $params): void
    {
        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-churn-risk-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('ChurnRisk');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    /**
     * Creates a real, non-canceled order for $customerId and backdates its created_at to exactly
     * $daysAgo days ago via a direct UPDATE (same technique CohortRetentionGridCollectionTest's
     * own createOrder() already establishes - Order's own setCreatedAt() isn't reliably honored
     * on save()).
     */
    private function createOrderDaysAgo(int $customerId, int $daysAgo): int
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
            ['created_at' => date('Y-m-d H:i:s', (int) strtotime("-{$daysAgo} days"))],
            ['entity_id = ?' => $orderId]
        );

        return $orderId;
    }

    private function deleteCampaign(int $campaignId): void
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaignResource->load($campaign, $campaignId);
        if ($campaign->getEntityId()) {
            $campaignResource->delete($campaign);
        }
    }

    private function flushCampaignCache(): void
    {
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);
    }

    // --- tests -------------------------------------------------------------------------------

    /**
     * Orders 40/30 days ago (median interval 10 days), last order 30 days ago is NOT the most
     * recent - add a third, more recent order 5 days ago too so the "most recent order" the
     * calculator measures elapsed time from is predictable: intervals [40->20]=20? Use exact,
     * easy-to-reason-about numbers: orders at 50/40/30 days ago -> intervals [10, 10], median 10,
     * elapsed since the last (30 days ago) = 30 days, ratio = 30/10 = 3.0.
     */
    public function testCustomerDriftingWellPastTheirOwnCadenceGetsTheAction(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrderDaysAgo($customerId, 50);
        $this->createOrderDaysAgo($customerId, 40);
        $this->createOrderDaysAgo($customerId, 30);

        $triggerEvent = 'test_churn_risk_hit_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, 1.5);
        $tag = 'churn-risk-hit-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$tag]]);
        $this->flushCampaignCache();
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertContains(
            $customerId,
            $this->tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag),
            'A customer whose real churn-risk ratio (3.0) is well past the configured threshold '
            . '(1.5) must have the real add_tag action run against them.'
        );
    }

    /**
     * Orders at 10/8/6 days ago -> intervals [2, 2], median 2, elapsed since the last (6 days
     * ago) = 6 days, ratio = 6/2 = 3.0 - still "at risk" in absolute terms, but comfortably below
     * a 5.0 threshold, proving this isn't a tautology that always passes.
     */
    public function testCustomerStillWithinTheirOwnCadenceDoesNotGetTheAction(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrderDaysAgo($customerId, 10);
        $this->createOrderDaysAgo($customerId, 8);
        $this->createOrderDaysAgo($customerId, 6);

        $triggerEvent = 'test_churn_risk_miss_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, 5.0);
        $tag = 'churn-risk-miss-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$tag]]);
        $this->flushCampaignCache();
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertSame(
            [],
            $this->tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag),
            'A customer whose real churn-risk ratio (3.0) is below the configured threshold (5.0) '
            . 'must NOT have the add_tag action run against them.'
        );
    }

    /**
     * A customer with only one non-canceled order has no interval to derive a baseline from -
     * ChurnRiskCalculator::ratioFromOrderDates() returns null, and isSatisfied() must fail closed
     * (never satisfied) regardless of how low the configured threshold is - proven here with a
     * threshold of 0, the most permissive value possible, to rule out any off-by-one that would
     * otherwise coincidentally pass.
     */
    public function testCustomerWithOnlyOneOrderNeverSatisfiesEvenAZeroThreshold(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrderDaysAgo($customerId, 5);

        $triggerEvent = 'test_churn_risk_one_order_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, 0.0);
        $tag = 'churn-risk-one-order-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$tag]]);
        $this->flushCampaignCache();
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertSame(
            [],
            $this->tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag),
            'A customer with no baseline interval to drift from must fail closed, even against a '
            . 'threshold of 0.'
        );
    }
}
