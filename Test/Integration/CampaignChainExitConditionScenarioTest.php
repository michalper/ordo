<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\RunScheduledCampaignActions;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/database coverage for docs/adr/0055's campaign chain exit condition - per that ADR's
 * own "Consequences" section: "This was verified with this repo's own standalone unit-test
 * toolchain only... No Integration/MFTF coverage was added in this change; real end-to-end
 * verification... is left to CI's own Integration Tests job." This suite is that follow-up: a
 * real multi-step delay_minutes chain, a real ordo_campaign.exit_conditions column read off a
 * real campaign row inside CampaignDispatcher::resumeScheduledAction(), and
 * Cron\RunScheduledCampaignActions actually skipping the remaining chain once the exit condition
 * is satisfied for real (a real tag, checked via the same `tag` condition type the campaign
 * engine's own condition evaluator already runs for entry conditions).
 *
 * Time is simulated the same way CampaignDispatchScenarioTest::testDelayedActionIsResumedByCron
 * AfterRunAtPasses() does - backdating ordo_campaign_scheduled_action.run_at directly instead of
 * waiting real wall-clock minutes.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes every
 * campaign/customer/tag this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/CampaignChainExitConditionScenarioTest.php
 */
class CampaignChainExitConditionScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $customerIds = [];

    /** @var array{customerId: int, tag: string}[] */
    private array $tagsToClean = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
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

        foreach ($this->customerIds as $customerId) {
            try {
                self::$objectManager->get(CustomerRepositoryInterface::class)->deleteById($customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->customerIds = [];
    }

    public function testExitConditionStopsTheChainBeforeTheDelayedStepResumes(): void
    {
        $customerId = $this->createCustomer();
        $exitTag = 'converted-' . uniqid('', true);

        $triggerEvent = 'test_exit_condition_stops_' . uniqid('', true);
        $campaignId = $this->createCampaign(
            $triggerEvent,
            json_encode([['type' => 'tag', 'params' => ['tags' => [$exitTag]]]])
        );
        $immediateTag = 'exit-immediate-' . uniqid('', true);
        $delayedTag = 'exit-delayed-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$immediateTag]], 0, 0);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$delayedTag]], 1, 1440);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertTrue($this->tagManager->hasTag($customerId, $immediateTag), 'the action before the delay must still run immediately - the initial dispatch never checks exit conditions');
        self::assertFalse($this->tagManager->hasTag($customerId, $delayedTag));
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $immediateTag];

        // The customer "reaches the goal" (e.g. places the order a drip was chasing) while the
        // delayed step is still pending.
        $this->tagManager->addTag($customerId, $exitTag);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $exitTag];

        $this->backdateScheduledAction($campaignId);

        $cron = self::$objectManager->get(RunScheduledCampaignActions::class);
        $cron->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $delayedTag),
            'the exit condition is now satisfied - the remaining chain must be skipped, not run'
        );
    }

    public function testChainResumesNormallyWhenTheExitConditionIsNeverSatisfied(): void
    {
        $customerId = $this->createCustomer();
        $neverTag = 'never-added-' . uniqid('', true);

        $triggerEvent = 'test_exit_condition_never_met_' . uniqid('', true);
        $campaignId = $this->createCampaign(
            $triggerEvent,
            json_encode([['type' => 'tag', 'params' => ['tags' => [$neverTag]]]])
        );
        $delayedTag = 'exit-resumes-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$delayedTag]], 0, 1440);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);
        self::assertFalse($this->tagManager->hasTag($customerId, $delayedTag));

        $this->backdateScheduledAction($campaignId);

        $cron = self::$objectManager->get(RunScheduledCampaignActions::class);
        $cron->execute();

        self::assertTrue(
            $this->tagManager->hasTag($customerId, $delayedTag),
            'an exit condition configured but never satisfied must behave exactly like a campaign with none - the chain resumes normally'
        );
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $delayedTag];
    }

    /**
     * Backward compatibility: a campaign with no exit_conditions at all (NULL, every campaign
     * predating this feature) must behave exactly as before - unconditional resume.
     */
    public function testNullExitConditionsNeverStopsTheChain(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_exit_condition_null_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, null);
        $delayedTag = 'exit-null-resumes-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$delayedTag]], 0, 1440);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);
        $this->backdateScheduledAction($campaignId);

        $cron = self::$objectManager->get(RunScheduledCampaignActions::class);
        $cron->execute();

        self::assertTrue($this->tagManager->hasTag($customerId, $delayedTag));
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $delayedTag];
    }

    private function backdateScheduledAction(int $campaignId): void
    {
        $scheduledCollectionFactory = self::$objectManager->get(ScheduledActionCollectionFactory::class);
        $scheduledCollection = $scheduledCollectionFactory->create();
        $scheduledCollection->addFieldToFilter('campaign_id', $campaignId);
        self::assertCount(1, $scheduledCollection, 'exactly one scheduled_action row must exist for the delayed action');

        /** @var CampaignScheduledAction $scheduled */
        $scheduled = $scheduledCollection->getFirstItem();
        $scheduledResource = self::$objectManager->get(CampaignScheduledActionResource::class);

        $scheduled->setRunAt(date('Y-m-d H:i:s', strtotime('-5 minutes')));
        $scheduledResource->save($scheduled);
    }

    private function createCampaign(string $triggerEvent, ?string $exitConditionsJson): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Exit condition integration test campaign ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaign->setExitConditions($exitConditionsJson);
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

    private function addAction(int $campaignId, string $type, array $params, int $sortOrder, int $delayMinutes): void
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

    private function flushCampaignCache(): void
    {
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-exit-condition-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('ExitCondition');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());

        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }
}
