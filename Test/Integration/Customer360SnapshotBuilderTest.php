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
use Ordo\Automation\Api\CustomerTagManagementInterface;
use Ordo\Automation\Model\Customer360\Customer360SnapshotBuilder;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for Model\Customer360\Customer360SnapshotBuilder - found via the
 * 2026-11-14 full-sweep audit (Test/Mftf/SCENARIOS.md §42, background docs/adr/0050): only ever
 * covered by Test/Unit with every collaborator mocked, which proves the class calls the right
 * methods but can't prove the real aggregation across several real tables (sales_order,
 * ordo_customer_tag, ordo_segment/ordo_segment_condition) actually lands in one coherent,
 * correct snapshot.
 *
 * RfmCalculator/ClvCalculator/ChurnRiskCalculator each already have their own dedicated real-data
 * coverage elsewhere (their own math is not what this test re-proves) - what's genuinely
 * unproven is the BUILDER's own wiring: that build() combines real order stats (its own inline
 * SQL), real tags, and real segment membership (via SegmentMatcher against a real "tag"
 * condition) for the SAME customer into one snapshot, using the same real orders every other
 * aggregate reads from.
 *
 * Orders are real (Order model + OrderResource, same technique Test/Integration/
 * CohortRetentionGridCollectionTest.php already establishes). No transactional rollback (see
 * magento-integration-test-lite) - tearDown() deletes every customer/order/tag/segment this test
 * created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/Customer360SnapshotBuilderTest.php
 */
class Customer360SnapshotBuilderTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $orderIds = [];

    /** @var int[] */
    private array $customerIds = [];

    /** @var int[] */
    private array $segmentIds = [];

    /** @var array{customerId: int, tag: string}[] */
    private array $tagsToClean = [];

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
        $tagManager = self::$objectManager->get(CustomerTagManagementInterface::class);
        foreach ($this->tagsToClean as $entry) {
            $tagManager->removeTag($entry['customerId'], $entry['tag']);
        }
        $this->tagsToClean = [];

        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);
        foreach ($this->segmentIds as $segmentId) {
            $segment = $segmentFactory->create();
            $segmentResource->load($segment, $segmentId);
            if ($segment->getEntityId()) {
                $segmentResource->delete($segment);
            }
        }
        $this->segmentIds = [];

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

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-customer360-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Snapshot');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function createOrderDaysAgo(int $customerId, int $daysAgo, float $grandTotal): int
    {
        /** @var Order $order */
        $order = self::$objectManager->get(OrderFactory::class)->create();
        $order->setCustomerId($customerId);
        $order->setState(Order::STATE_COMPLETE);
        $order->setStatus('complete');
        $order->setStoreId(1);
        $order->setGrandTotal($grandTotal);
        $order->setBaseGrandTotal($grandTotal);
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

    private function createSegmentMatchingTag(string $tag, bool $enabled = true): int
    {
        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);

        $segment = $segmentFactory->create();
        $segment->setName('Customer 360 test segment ' . uniqid('', true));
        $segment->setEnabled($enabled);
        $segmentResource->save($segment);
        $segmentId = (int) $segment->getEntityId();
        $this->segmentIds[] = $segmentId;

        $conditionFactory = self::$objectManager->get(SegmentConditionFactory::class);
        $conditionResource = self::$objectManager->get(SegmentConditionResource::class);
        $condition = $conditionFactory->create();
        $condition->setData([
            'segment_id' => $segmentId,
            'type' => 'tag',
            'params' => json_encode(['tags' => [$tag], 'match_mode' => 'any']),
            'sort_order' => 0,
        ]);
        $conditionResource->save($condition);

        return $segmentId;
    }

    // --- tests -------------------------------------------------------------------------------

    public function testBuildAggregatesRealOrderStatsTagsScoreAndMatchingSegmentsForTheSameCustomer(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrderDaysAgo($customerId, daysAgo: 20, grandTotal: 30.0);
        $this->createOrderDaysAgo($customerId, daysAgo: 10, grandTotal: 50.0);

        $tag = 'customer360-test-' . uniqid('', true);
        $tagManager = self::$objectManager->get(CustomerTagManagementInterface::class);
        $tagManager->addTag($customerId, $tag);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $matchingSegmentId = $this->createSegmentMatchingTag($tag);
        // A real, enabled segment that genuinely does NOT match this customer - proves
        // matchingSegmentNames is a real per-customer match, not just every enabled segment's
        // name listed unconditionally.
        $nonMatchingSegmentId = $this->createSegmentMatchingTag('customer360-test-unrelated-' . uniqid('', true));

        self::$objectManager->get(CustomerScoreManager::class)->addPoints($customerId, 42);

        $customer = self::$objectManager->get(CustomerRepositoryInterface::class)->getById($customerId);
        /** @var Customer360SnapshotBuilder $builder */
        $builder = self::$objectManager->get(Customer360SnapshotBuilder::class);
        $snapshot = $builder->build($customer);

        self::assertSame($customerId, $snapshot->customerId);
        self::assertSame(2, $snapshot->orderCount, 'Real non-canceled order count must be exactly 2.');
        self::assertSame(80.0, $snapshot->orderTotal, 'Real order total must be the sum of both real orders\' grand_total.');
        self::assertSame(42, $snapshot->leadScore);
        self::assertContains($tag, $snapshot->tags);
        self::assertGreaterThan(
            0.0,
            $snapshot->projectedClv,
            'A customer with real order history must get a real projected CLV greater than zero, not a stub value.'
        );
        self::assertNotNull(
            $snapshot->churnRiskRatio,
            'A customer with 2 real non-canceled orders has a real interval to derive a churn-risk ratio from.'
        );

        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);
        $matchingSegment = $segmentFactory->create();
        $segmentResource->load($matchingSegment, $matchingSegmentId);
        $nonMatchingSegment = $segmentFactory->create();
        $segmentResource->load($nonMatchingSegment, $nonMatchingSegmentId);

        self::assertContains($matchingSegment->getName(), $snapshot->matchingSegmentNames);
        self::assertNotContains($nonMatchingSegment->getName(), $snapshot->matchingSegmentNames);
    }

    public function testBuildReturnsZeroOrderStatsForACustomerWithNoOrdersAtAll(): void
    {
        $customerId = $this->createCustomer();

        $customer = self::$objectManager->get(CustomerRepositoryInterface::class)->getById($customerId);
        /** @var Customer360SnapshotBuilder $builder */
        $builder = self::$objectManager->get(Customer360SnapshotBuilder::class);
        $snapshot = $builder->build($customer);

        self::assertSame(0, $snapshot->orderCount);
        self::assertSame(0.0, $snapshot->orderTotal, 'COALESCE must turn a NULL SUM() into 0.0, not leave it null or error.');
        self::assertNull($snapshot->churnRiskRatio, 'No orders at all means no baseline to derive a churn-risk ratio from.');
    }
}
