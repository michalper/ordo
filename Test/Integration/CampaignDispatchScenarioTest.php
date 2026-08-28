<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\RuleFactory;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Cron\RunScheduledCampaignActions;
use PHPUnit\Framework\TestCase;

/**
 * Real end-to-end coverage of the campaign engine — real DI, real dev database, no mocks on
 * the dispatch path. Deliberately bypasses the observer/queue layer (each test calls
 * CampaignDispatcher::dispatch() directly with a synthetic context) since that wiring is
 * covered separately by CampaignQueueWiringTest; this suite is about the engine itself:
 * every condition type, every action type, multi-condition AND, unknown-type fail-closed,
 * delayed chains + cron resume, multi-trigger campaigns, and the trigger->campaign cache.
 *
 * No transactional rollback (see magento-integration-test-lite) — every test tracks what it
 * creates and deletes it in tearDown().
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/CampaignDispatchScenarioTest.php
 */
class CampaignDispatchScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var array{customerId: int, tag: string}[] */
    private array $tagsToClean = [];

    /** @var int[] */
    private array $ruleIdsToClean = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        // Magento\Framework\Model\ActionValidator\RemoveAction refuses to delete a Customer
        // (it's on the "protected models" list, meant to stop accidental self-deletion in
        // request context) unless this registry flag is set — the standard escape hatch every
        // Magento integration test/cron/CLI context uses for cleanup deletes.
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
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

        foreach ($this->ruleIdsToClean as $ruleId) {
            $this->deleteRule($ruleId);
        }
        $this->ruleIdsToClean = [];
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createCampaign(string $triggerEvent, bool $enabled = true): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test campaign ' . uniqid('', true));
        $campaign->setEnabled($enabled);
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

    private function addCondition(int $campaignId, string $type, array $params, int $sortOrder = 0): void
    {
        $factory = self::$objectManager->get(CampaignConditionFactory::class);
        $resource = self::$objectManager->get(CampaignConditionResource::class);
        /** @var CampaignCondition $condition */
        $condition = $factory->create();
        $condition->setData([
            'campaign_id' => $campaignId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => $sortOrder,
        ]);
        $resource->save($condition);
    }

    private function addAction(int $campaignId, string $type, array $params, int $sortOrder = 0, int $delayMinutes = 0): int
    {
        $factory = self::$objectManager->get(CampaignActionFactory::class);
        $resource = self::$objectManager->get(CampaignActionResource::class);
        /** @var CampaignAction $action */
        $action = $factory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => $sortOrder,
            'delay_minutes' => $delayMinutes,
        ]);
        $resource->save($action);

        return (int) $action->getEntityId();
    }

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

    private function createRule(): int
    {
        /** @var RuleFactory $ruleFactory */
        $ruleFactory = self::$objectManager->get(RuleFactory::class);
        $ruleResource = self::$objectManager->get(RuleResource::class);
        $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);

        $rule = $ruleFactory->create();
        $rule->setName('Integration test rule ' . uniqid('', true));
        $rule->setWebsiteIds([(int) $storeManager->getWebsite()->getId()]);
        $rule->setCustomerGroupIds([0, 1, 2, 3]);
        $rule->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC);
        $rule->setSimpleAction('by_percent');
        $rule->setDiscountAmount(10);
        $rule->setIsActive(true);
        $rule->setFromDate(date('Y-m-d'));
        $ruleResource->save($rule);

        return (int) $rule->getRuleId();
    }

    private function deleteRule(int $ruleId): void
    {
        try {
            $ruleResource = self::$objectManager->get(RuleResource::class);
            $ruleFactory = self::$objectManager->get(RuleFactory::class);
            $rule = $ruleFactory->create();
            $ruleResource->load($rule, $ruleId);
            if ($rule->getRuleId()) {
                $ruleResource->delete($rule);
            }
        } catch (\Throwable $e) {
            // Best-effort cleanup only.
        }
    }

    private function deleteCampaign(int $campaignId): void
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaign = $campaignFactory->create();
        $campaignResource->load($campaign, $campaignId);
        if ($campaign->getEntityId()) {
            // Triggers/conditions/actions/scheduled-actions cascade-delete via FK ON DELETE
            // CASCADE (etc/db_schema.xml) — no need to delete them individually.
            $campaignResource->delete($campaign);
        }
    }

    private function flushCampaignCache(): void
    {
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);
    }

    // --- conditions --------------------------------------------------------------------------

    public function testTagConditionSatisfiedAllowsActionToRun(): void
    {
        $customerId = $this->createCustomer();
        $tag = 'vip-' . uniqid('', true);
        $this->tagManager->addTag($customerId, $tag);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $triggerEvent = 'test_tag_condition_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $this->addCondition($campaignId, 'tag', ['tag' => $tag]);
        $resultTag = 'result-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $resultTag]);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $resultTag];
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertTrue($this->tagManager->hasTag($customerId, $resultTag));
        $this->deleteCustomer($customerId);
    }

    public function testTagConditionNotSatisfiedSkipsAction(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_tag_condition_fail_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $this->addCondition($campaignId, 'tag', ['tag' => 'never-added-' . uniqid('', true)]);
        $resultTag = 'result-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $resultTag]);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertFalse($this->tagManager->hasTag($customerId, $resultTag));
        $this->deleteCustomer($customerId);
    }

    public function testOrderTotalConditionReadsFromContextWithoutAnyDbLookup(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_order_total_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $this->addCondition($campaignId, 'order_total_gte', ['amount' => '100']);
        $resultTag = 'big-spender-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $resultTag]);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId, 'order_total' => 50.0]);
        self::assertFalse($this->tagManager->hasTag($customerId, $resultTag), 'below threshold must not tag');

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId, 'order_total' => 150.0]);
        self::assertTrue($this->tagManager->hasTag($customerId, $resultTag), 'above threshold must tag');

        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $resultTag];
        $this->deleteCustomer($customerId);
    }

    public function testMultipleConditionsMustAllBeSatisfied(): void
    {
        $customerId = $this->createCustomer();
        $tag = 'segment-' . uniqid('', true);
        $this->tagManager->addTag($customerId, $tag);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $triggerEvent = 'test_multi_condition_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $this->addCondition($campaignId, 'tag', ['tag' => $tag], 0);
        $this->addCondition($campaignId, 'order_total_gte', ['amount' => '100'], 1);
        $resultTag = 'qualified-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $resultTag]);
        $this->flushCampaignCache();

        // Has the tag but not the order total — AND must fail.
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId, 'order_total' => 10.0]);
        self::assertFalse($this->tagManager->hasTag($customerId, $resultTag));

        // Both satisfied now.
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId, 'order_total' => 500.0]);
        self::assertTrue($this->tagManager->hasTag($customerId, $resultTag));

        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $resultTag];
        $this->deleteCustomer($customerId);
    }

    public function testUnknownConditionTypeFailsClosedWithoutBreakingOtherCampaigns(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_unknown_condition_' . uniqid('', true);

        $brokenCampaignId = $this->createCampaign($triggerEvent);
        $this->addCondition($brokenCampaignId, 'this_type_does_not_exist', []);
        $neverTag = 'never-' . uniqid('', true);
        $this->addAction($brokenCampaignId, 'add_tag', ['tag' => $neverTag]);

        $healthyCampaignId = $this->createCampaign($triggerEvent);
        $healthyTag = 'healthy-' . uniqid('', true);
        $this->addAction($healthyCampaignId, 'add_tag', ['tag' => $healthyTag]);

        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertFalse($this->tagManager->hasTag($customerId, $neverTag), 'unknown condition type must fail closed');
        self::assertTrue($this->tagManager->hasTag($customerId, $healthyTag), 'a broken campaign must not break other campaigns on the same trigger');

        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $healthyTag];
        $this->deleteCustomer($customerId);
    }

    public function testUnknownActionTypeIsLoggedAndSkippedWithoutThrowing(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_unknown_action_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $this->addAction($campaignId, 'this_action_does_not_exist', [], 0);
        $this->flushCampaignCache();

        // Must not throw — the whole point of fail-closed is dispatch() completing normally.
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        $this->deleteCustomer($customerId);
        self::assertTrue(true);
    }

    // --- actions -------------------------------------------------------------------------

    public function testGenerateCouponActionCreatesARealCouponRow(): void
    {
        $customerId = $this->createCustomer();
        $ruleId = $this->createRule();
        $this->ruleIdsToClean[] = $ruleId;

        $triggerEvent = 'test_generate_coupon_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $prefix = 'ITEST' . substr(uniqid('', false), -6);
        $this->addAction($campaignId, 'generate_coupon', ['rule_id' => (string) $ruleId, 'prefix' => $prefix]);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        $couponCollectionFactory = self::$objectManager->get(\Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory::class);
        $collection = $couponCollectionFactory->create();
        $collection->addFieldToFilter('rule_id', $ruleId);
        $collection->addFieldToFilter('code', ['like' => $prefix . '%']);

        self::assertCount(1, $collection, 'generate_coupon must create exactly one real salesrule_coupon row');

        /** @var \Magento\SalesRule\Model\Coupon $coupon */
        $coupon = $collection->getFirstItem();
        self::assertSame($ruleId, (int) $coupon->getRuleId());

        self::$objectManager->get(CouponResource::class)->delete($coupon);
        $this->deleteCustomer($customerId);
    }

    // --- delayed actions / cron resume ----------------------------------------------------

    public function testDelayedActionIsResumedByCronAfterRunAtPasses(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_delay_chain_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $immediateTag = 'immediate-' . uniqid('', true);
        $delayedTag = 'delayed-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $immediateTag], 0, 0);
        $this->addAction($campaignId, 'add_tag', ['tag' => $delayedTag], 1, 1440);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertTrue($this->tagManager->hasTag($customerId, $immediateTag), 'the action before the delay must run immediately');
        self::assertFalse($this->tagManager->hasTag($customerId, $delayedTag), 'the delayed action must not run yet');

        $scheduledCollectionFactory = self::$objectManager->get(ScheduledActionCollectionFactory::class);
        $scheduledCollection = $scheduledCollectionFactory->create();
        $scheduledCollection->addFieldToFilter('campaign_id', $campaignId);
        self::assertCount(1, $scheduledCollection, 'exactly one scheduled_action row must exist for the delayed action');

        /** @var CampaignScheduledAction $scheduled */
        $scheduled = $scheduledCollection->getFirstItem();
        $scheduledResource = self::$objectManager->get(CampaignScheduledActionResource::class);

        // Simulate time passing without waiting a real 24h — back-date run_at directly.
        $scheduled->setRunAt(date('Y-m-d H:i:s', strtotime('-5 minutes')));
        $scheduledResource->save($scheduled);

        $cron = self::$objectManager->get(RunScheduledCampaignActions::class);
        $cron->execute();

        self::assertTrue($this->tagManager->hasTag($customerId, $delayedTag), 'the cron must resume and run the delayed action once run_at has passed');

        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $immediateTag];
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $delayedTag];
        $this->deleteCustomer($customerId);
    }

    // --- multi-trigger -----------------------------------------------------------------------

    public function testCampaignFiresOnEitherOfItsMultipleTriggerEvents(): void
    {
        $customerId = $this->createCustomer();

        $eventA = 'test_multi_trigger_a_' . uniqid('', true);
        $eventB = 'test_multi_trigger_b_' . uniqid('', true);

        $campaignId = $this->createCampaign($eventA);
        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $secondTrigger */
        $secondTrigger = $triggerFactory->create();
        $secondTrigger->setData(['campaign_id' => $campaignId, 'trigger_event' => $eventB]);
        $triggerResource->save($secondTrigger);

        $tag = 'multi-trigger-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $tag]);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($eventB, ['customer_id' => $customerId]);

        self::assertTrue($this->tagManager->hasTag($customerId, $tag), 'the campaign must fire on its second trigger event too, not just the first');

        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];
        $this->deleteCustomer($customerId);
    }

    // --- cache ---------------------------------------------------------------------------

    public function testTriggerCampaignLookupIsCachedUntilExplicitlyInvalidated(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_cache_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $tag = 'cached-run-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tag' => $tag]);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);
        self::assertTrue($this->tagManager->hasTag($customerId, $tag));
        $this->tagManager->removeTag($customerId, $tag);

        // Disable the campaign via the resource model directly (bypassing CampaignRepository /
        // the admin controller, which are what actually clean the cache) — this proves the
        // dispatcher is reading a CACHED answer, not re-querying the database every time.
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaign = $campaignFactory->create();
        $campaignResource->load($campaign, $campaignId);
        $campaign->setEnabled(false);
        $campaignResource->save($campaign);

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);
        self::assertTrue(
            $this->tagManager->hasTag($customerId, $tag),
            'the dispatcher must still be serving the stale cached "enabled" answer'
        );
        $this->tagManager->removeTag($customerId, $tag);

        // Now explicitly invalidate — the disabled campaign must stop firing.
        $this->flushCampaignCache();
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);
        self::assertFalse(
            $this->tagManager->hasTag($customerId, $tag),
            'after invalidation the dispatcher must see the campaign is now disabled'
        );

        $this->deleteCustomer($customerId);
    }
}
