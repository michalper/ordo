<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\Campaign\Condition\MonetaryTotalAtLeast;
use Ordo\Automation\Model\Campaign\Condition\OrderFrequencyAtLeast;
use Ordo\Automation\Model\Campaign\Condition\RecencyDaysAtMost;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\Segment\SegmentMatcher;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use PHPUnit\Framework\TestCase;

/**
 * Real end-to-end coverage of saved segments and the RFM condition trio, against a real
 * database — same conventions as CampaignDispatchScenarioTest (real DI, no rollback, every
 * test cleans up in tearDown()). Orders are inserted directly into sales_order rather than
 * built through a real checkout — the RFM conditions/RfmCalculator only ever read customer_id/
 * grand_total/state/created_at from that table (see Model\Rfm\RfmCalculator), so a direct
 * insert exercises the exact same read path a real order would, without the overhead/fragility
 * of driving a full quote-to-order flow just to get rows into that table.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/SegmentAndRfmScenarioTest.php
 */
class SegmentAndRfmScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;
    private SegmentMatcher $segmentMatcher;
    private ResourceConnection $resourceConnection;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $segmentIds = [];

    /** @var int[] */
    private array $orderIds = [];

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

    protected function setUp(): void
    {
        $this->dispatcher = self::$objectManager->get(CampaignDispatcher::class);
        $this->cache = self::$objectManager->get(CacheInterface::class);
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
        $this->segmentMatcher = self::$objectManager->get(SegmentMatcher::class);
        $this->resourceConnection = self::$objectManager->get(ResourceConnection::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $orderId) {
            $this->resourceConnection->getConnection()->delete(
                $this->resourceConnection->getTableName('sales_order'),
                ['entity_id = ?' => $orderId]
            );
        }
        $this->orderIds = [];

        foreach ($this->tagsToClean as $entry) {
            $this->tagManager->removeTag($entry['customerId'], $entry['tag']);
        }
        $this->tagsToClean = [];

        foreach ($this->campaignIds as $campaignId) {
            $this->deleteCampaign($campaignId);
        }
        $this->campaignIds = [];

        foreach ($this->segmentIds as $segmentId) {
            $this->deleteSegment($segmentId);
        }
        $this->segmentIds = [];
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);

        $email = 'ordo-automation-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Integration');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getWebsite()->getId());

        $saved = $customerRepository->save($customer);

        return (int) $saved->getId();
    }

    private function deleteCustomer(int $customerId): void
    {
        try {
            self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->deleteById($customerId);
        } catch (\Throwable $e) {
            // Already gone or never fully committed — nothing left to clean up.
        }
    }

    private function createOrder(int $customerId, float $grandTotal, int $daysAgo, string $state = 'complete'): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        $storeId = (int) self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore()->getId();

        $connection->insert($table, [
            'customer_id' => $customerId,
            'grand_total' => $grandTotal,
            'state' => $state,
            'status' => $state,
            'store_id' => $storeId,
            'created_at' => date('Y-m-d H:i:s', time() - $daysAgo * 86400),
            'increment_id' => 'ITEST-' . uniqid('', true),
        ]);

        $orderId = (int) $connection->lastInsertId($table);
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function createSegment(bool $enabled = true): int
    {
        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);

        $segment = $segmentFactory->create();
        $segment->setName('Integration test segment ' . uniqid('', true));
        $segment->setEnabled($enabled);
        $segmentResource->save($segment);
        $segmentId = (int) $segment->getEntityId();
        $this->segmentIds[] = $segmentId;

        return $segmentId;
    }

    private function addSegmentCondition(int $segmentId, string $type, array $params, int $sortOrder = 0): void
    {
        $factory = self::$objectManager->get(SegmentConditionFactory::class);
        $resource = self::$objectManager->get(SegmentConditionResource::class);

        $condition = $factory->create();
        $condition->setData([
            'segment_id' => $segmentId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => $sortOrder,
        ]);
        $resource->save($condition);
    }

    private function deleteSegment(int $segmentId): void
    {
        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);
        $segment = $segmentFactory->create();
        $segmentResource->load($segment, $segmentId);
        if ($segment->getEntityId()) {
            // Condition rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
            $segmentResource->delete($segment);
        }
    }

    private function createCampaign(string $triggerEvent): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test campaign ' . uniqid('', true));
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

        return $campaignId;
    }

    private function addCondition(int $campaignId, string $type, array $params): void
    {
        $factory = self::$objectManager->get(CampaignConditionFactory::class);
        $resource = self::$objectManager->get(CampaignConditionResource::class);
        $condition = $factory->create();
        $condition->setData([
            'campaign_id' => $campaignId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => 0,
        ]);
        $resource->save($condition);
    }

    private function addAction(int $campaignId, string $type, array $params): void
    {
        $factory = self::$objectManager->get(CampaignActionFactory::class);
        $resource = self::$objectManager->get(CampaignActionResource::class);
        $action = $factory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $resource->save($action);
    }

    private function deleteCampaign(int $campaignId): void
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaign = $campaignFactory->create();
        $campaignResource->load($campaign, $campaignId);
        if ($campaign->getEntityId()) {
            $campaignResource->delete($campaign);
        }
    }

    // --- RFM conditions ----------------------------------------------------------------------

    public function testOrderFrequencyAtLeastConditionMatchesARealOrderCount(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrder($customerId, 50.0, 1);
        $this->createOrder($customerId, 50.0, 2);

        /** @var OrderFrequencyAtLeast $condition */
        $condition = self::$objectManager->get(OrderFrequencyAtLeast::class);
        $context = ['customer_id' => $customerId];

        self::assertTrue($condition->isSatisfied($context, ['count' => '2']));
        self::assertFalse($condition->isSatisfied($context, ['count' => '3']));

        $this->deleteCustomer($customerId);
    }

    public function testMonetaryTotalAtLeastConditionMatchesARealOrderSum(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrder($customerId, 120.0, 1);
        $this->createOrder($customerId, 180.0, 5);

        /** @var MonetaryTotalAtLeast $condition */
        $condition = self::$objectManager->get(MonetaryTotalAtLeast::class);
        $context = ['customer_id' => $customerId];

        self::assertTrue($condition->isSatisfied($context, ['amount' => '300']));
        self::assertFalse($condition->isSatisfied($context, ['amount' => '301']));

        $this->deleteCustomer($customerId);
    }

    public function testRecencyDaysAtMostConditionMatchesARealOrderDate(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrder($customerId, 50.0, 5);

        /** @var RecencyDaysAtMost $condition */
        $condition = self::$objectManager->get(RecencyDaysAtMost::class);
        $context = ['customer_id' => $customerId];

        self::assertTrue($condition->isSatisfied($context, ['days' => '10']));
        self::assertFalse($condition->isSatisfied($context, ['days' => '1']));

        $this->deleteCustomer($customerId);
    }

    public function testCanceledOrdersAreExcludedFromEveryRfmMetric(): void
    {
        $customerId = $this->createCustomer();
        $this->createOrder($customerId, 1000.0, 1, 'canceled');

        $frequency = self::$objectManager->get(OrderFrequencyAtLeast::class);
        $monetary = self::$objectManager->get(MonetaryTotalAtLeast::class);
        $recency = self::$objectManager->get(RecencyDaysAtMost::class);
        $context = ['customer_id' => $customerId];

        self::assertFalse($frequency->isSatisfied($context, ['count' => '1']));
        self::assertFalse($monetary->isSatisfied($context, ['amount' => '1']));
        self::assertFalse($recency->isSatisfied($context, ['days' => '30']));

        $this->deleteCustomer($customerId);
    }

    // --- segments ------------------------------------------------------------------------

    public function testSegmentWithNoConditionsNeverMatchesAnyone(): void
    {
        $customerId = $this->createCustomer();
        $segmentId = $this->createSegment();

        self::assertFalse($this->segmentMatcher->isCustomerInSegment($segmentId, $customerId));

        $this->deleteCustomer($customerId);
    }

    public function testSegmentMatcherAndsRealRfmConditionsAgainstRealOrders(): void
    {
        $matchingCustomerId = $this->createCustomer();
        $this->createOrder($matchingCustomerId, 200.0, 1);
        $this->createOrder($matchingCustomerId, 200.0, 2);

        $partialCustomerId = $this->createCustomer();
        $this->createOrder($partialCustomerId, 50.0, 1);

        $segmentId = $this->createSegment();
        $this->addSegmentCondition($segmentId, 'order_frequency_at_least', ['count' => '2'], 0);
        $this->addSegmentCondition($segmentId, 'monetary_total_at_least', ['amount' => '300'], 1);

        self::assertTrue(
            $this->segmentMatcher->isCustomerInSegment($segmentId, $matchingCustomerId),
            'a customer meeting BOTH AND-ed conditions must match'
        );
        self::assertFalse(
            $this->segmentMatcher->isCustomerInSegment($segmentId, $partialCustomerId),
            'a customer meeting only one of the AND-ed conditions must not match'
        );

        $this->deleteCustomer($matchingCustomerId);
        $this->deleteCustomer($partialCustomerId);
    }

    public function testInSegmentConditionGatesARealCampaignDispatchOnRealSegmentMembership(): void
    {
        $memberCustomerId = $this->createCustomer();
        $this->createOrder($memberCustomerId, 500.0, 1);

        $nonMemberCustomerId = $this->createCustomer();

        $segmentId = $this->createSegment();
        $this->addSegmentCondition($segmentId, 'monetary_total_at_least', ['amount' => '100']);

        $triggerEvent = 'test_in_segment_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $this->addCondition($campaignId, 'in_segment', ['segment_id' => (string) $segmentId]);
        $resultTag = 'segment-member-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $resultTag]);
        $this->tagsToClean[] = ['customerId' => $memberCustomerId, 'tag' => $resultTag];
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $nonMemberCustomerId]);
        self::assertFalse($this->tagManager->hasTag($nonMemberCustomerId, $resultTag));

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $memberCustomerId]);
        self::assertTrue($this->tagManager->hasTag($memberCustomerId, $resultTag));

        $this->deleteCustomer($memberCustomerId);
        $this->deleteCustomer($nonMemberCustomerId);
    }
}
