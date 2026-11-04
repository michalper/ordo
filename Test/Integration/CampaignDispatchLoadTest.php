<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Cron\RunScheduledCampaignActions;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\CampaignScheduledActionFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Puts a concrete number on the two "Phase 7" architectural fixes ROADMAP.md flagged as
 * untested at scale: CampaignDispatcher's batched (not per-campaign) condition/action loading,
 * and RunScheduledCampaignActions's bounded batch loop. Both were fixed to no longer be O(n)
 * against the database, but nothing had run either one against a large N to prove it.
 *
 * These are deliberately heavier than CampaignDispatchScenarioTest's correctness cases — real
 * bulk fixture creation (hundreds of rows), real wall-clock timing — so they're kept in a
 * separate file rather than slowing down every run of the main scenario suite. Same
 * magento-integration-test-lite approach: real bootstrap, real DB, no transactional rollback,
 * manual cleanup in tearDown().
 *
 * The wall-clock bounds asserted here are deliberately generous (an order of magnitude above
 * what a healthy run takes locally) — the point is catching a regression back to O(n) query
 * behavior (which would blow well past these bounds, not just miss them narrowly), not
 * micro-benchmarking a specific number that would make this test flaky on a slower CI runner.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/CampaignDispatchLoadTest.php
 */
class CampaignDispatchLoadTest extends TestCase
{
    private const int CAMPAIGN_COUNT = 200;
    private const int SCHEDULED_ACTION_COUNT = 600; // > RunScheduledCampaignActions::BATCH_SIZE (500)

    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

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
    }

    /**
     * CampaignDispatcher::dispatch() loads conditions/actions for every matched campaign in one
     * query each (addCampaignIdsFilter), not one query per campaign — this proves that holds at
     * a scale (200 campaigns on one trigger) where the old per-campaign version would have run
     * 200x more queries, and puts a concrete wall-clock ceiling on the batched version.
     */
    public function testDispatchThroughputWithManyCampaignsOnOneTrigger(): void
    {
        $customerId = $this->createCustomer();
        $eligibilityTag = 'load-eligible-' . uniqid('', true);
        $this->tagManager->addTag($customerId, $eligibilityTag);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $eligibilityTag];

        $triggerEvent = 'test_load_dispatch_' . uniqid('', true);
        $resultTags = [];

        for ($i = 0; $i < self::CAMPAIGN_COUNT; $i++) {
            $campaignId = $this->createCampaign($triggerEvent);
            $this->addCondition($campaignId, 'tag', ['tags' => [$eligibilityTag]]);
            $resultTag = sprintf('load-result-%d-%s', $i, uniqid('', true));
            $this->addAction($campaignId, 'add_tag', ['tags' => [$resultTag]]);
            $resultTags[] = $resultTag;
        }

        $this->flushCampaignCache();

        $start = microtime(true);
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);
        $elapsedSeconds = microtime(true) - $start;

        fwrite(
            STDERR,
            sprintf(
                "\nCampaignDispatcher::dispatch() with %d matched campaigns: %.3fs (%.1f campaigns/sec)\n",
                self::CAMPAIGN_COUNT,
                $elapsedSeconds,
                self::CAMPAIGN_COUNT / max($elapsedSeconds, 0.001)
            )
        );

        self::assertLessThan(
            15.0,
            $elapsedSeconds,
            sprintf(
                'dispatch() with %d matched campaigns took %.3fs — the batched condition/action '
                . 'loading this asserts on regressed back toward one query per campaign.',
                self::CAMPAIGN_COUNT,
                $elapsedSeconds
            )
        );

        // Spot-check first, a middle, and the last campaign rather than all 200 — correctness of
        // the AND/action-execution logic itself is already covered exhaustively by
        // CampaignDispatchScenarioTest; this only needs to prove the batch actually ran end to end.
        foreach ([0, (int) (self::CAMPAIGN_COUNT / 2), self::CAMPAIGN_COUNT - 1] as $index) {
            self::assertTrue(
                $this->tagManager->hasTag($customerId, $resultTags[$index]),
                "campaign #{$index} of " . self::CAMPAIGN_COUNT . ' must still have run its action'
            );
            $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $resultTags[$index]];
        }

        $this->deleteCustomer($customerId);
    }

    /**
     * RunScheduledCampaignActions processes due rows in fixed-size batches (500), re-querying
     * page 1 each time, instead of loading every due row into memory up front. This backlog
     * (600 rows) deliberately exceeds one batch, so a single cron tick must loop twice — proving
     * the batch loop actually advances (each batch's claim moves rows out of the "due" filter)
     * rather than re-processing the same page forever, and that it completes within a bounded
     * time instead of the unbounded "load everything, then work through it" shape this replaced.
     */
    public function testScheduledActionCronProcessesBacklogSpanningMultipleBatches(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_load_cron_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent);
        $delayedTag = 'load-cron-delayed-' . uniqid('', true);
        $delayedActionId = $this->addAction($campaignId, 'add_tag', ['tags' => [$delayedTag]], 0, 1440);

        $scheduledFactory = self::$objectManager->get(CampaignScheduledActionFactory::class);
        $scheduledResource = self::$objectManager->get(CampaignScheduledActionResource::class);

        $pastRunAt = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        for ($i = 0; $i < self::SCHEDULED_ACTION_COUNT; $i++) {
            /** @var CampaignScheduledAction $scheduled */
            $scheduled = $scheduledFactory->create();
            $scheduled->setCampaignId($campaignId);
            $scheduled->setResumeActionId($delayedActionId);
            $scheduled->setContext(['customer_id' => $customerId]);
            $scheduled->setRunAt($pastRunAt);
            $scheduledResource->save($scheduled);
        }

        $cron = self::$objectManager->get(RunScheduledCampaignActions::class);

        $start = microtime(true);
        $cron->execute();
        $elapsedSeconds = microtime(true) - $start;

        fwrite(
            STDERR,
            sprintf(
                "\nRunScheduledCampaignActions::execute() over a %d-row backlog (batch size 500): "
                . "%.3fs (%.1f rows/sec)\n",
                self::SCHEDULED_ACTION_COUNT,
                $elapsedSeconds,
                self::SCHEDULED_ACTION_COUNT / max($elapsedSeconds, 0.001)
            )
        );

        self::assertLessThan(
            60.0,
            $elapsedSeconds,
            sprintf(
                'a single cron tick over a %d-row backlog (just above the 500-row batch size) took '
                . '%.3fs — the batched claim-then-resume loop this asserts on regressed toward '
                . 'loading the whole backlog unbounded.',
                self::SCHEDULED_ACTION_COUNT,
                $elapsedSeconds
            )
        );

        self::assertTrue($this->tagManager->hasTag($customerId, $delayedTag));
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $delayedTag];

        $scheduledCollectionFactory = self::$objectManager->get(ScheduledActionCollectionFactory::class);
        $allForCampaign = $scheduledCollectionFactory->create();
        $allForCampaign->addFieldToFilter('campaign_id', $campaignId);
        $stillDue = 0;
        foreach ($allForCampaign as $row) {
            /** @var CampaignScheduledAction $row */
            if ($row->getExecutedAt() === null) {
                $stillDue++;
            }
        }

        self::assertSame(
            0,
            $stillDue,
            'every row in the backlog must be claimed within this single cron tick — the batch '
            . 'loop re-queries page 1 after each claim, so nothing should be left behind under '
            . self::SCHEDULED_ACTION_COUNT . ' rows (well under the 20-batch / 10,000-row cap).'
        );

        $this->deleteCustomer($customerId);
    }

    // --- fixture builders (same shape as CampaignDispatchScenarioTest) ---------------------

    private function createCampaign(string $triggerEvent, bool $enabled = true): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Load test campaign ' . uniqid('', true));
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

    private function addAction(
        int $campaignId,
        string $type,
        array $params,
        int $sortOrder = 0,
        int $delayMinutes = 0
    ): int {
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

        $email = 'ordo-automation-load-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Load');
        $customer->setLastname('Test');
        $customer->setWebsiteId(
            (int) self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)
                ->getWebsite()->getId()
        );

        $saved = $customerRepository->save($customer);

        return (int) $saved->getId();
    }

    private function deleteCustomer(int $customerId): void
    {
        try {
            self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
                ->deleteById($customerId);
        } catch (\Throwable $e) {
            // Already gone or never fully committed — nothing left to clean up.
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
}
