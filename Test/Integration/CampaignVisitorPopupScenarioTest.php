<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Model\VisitorEventLogger;
use Ordo\Automation\Model\VisitorTagManager;
use PHPUnit\Framework\TestCase;

/**
 * Real end-to-end coverage of the anonymous-visitor path added alongside the "popup" action:
 * real visitor event aggregation into a visitor tag, real dispatch of the visitor_tag_added
 * trigger, a real "visitor_tag" condition, and a real "popup" action — all the way to a real
 * ordo_pending_popup row, then a real claim of that row through the exact query/UPDATE
 * Controller\Track\Popup uses, against a real database (not a mocked SQL builder, which can't
 * prove the OR-across-columns filter and the conditional UPDATE actually behave as intended in
 * real MySQL).
 *
 * Same conventions as CampaignDispatchScenarioTest: real DI, real dev database, no rollback —
 * every test cleans up what it creates in tearDown().
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/CampaignVisitorPopupScenarioTest.php
 */
class CampaignVisitorPopupScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private VisitorTagManager $visitorTagManager;
    private VisitorEventLogger $visitorEventLogger;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var array{visitorId: string, tag: string}[] */
    private array $visitorTagsToClean = [];

    private ?string $cleanupVisitorId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    }

    protected function setUp(): void
    {
        $this->dispatcher = self::$objectManager->get(CampaignDispatcher::class);
        $this->cache = self::$objectManager->get(CacheInterface::class);
        $this->visitorTagManager = self::$objectManager->get(VisitorTagManager::class);
        $this->visitorEventLogger = self::$objectManager->get(VisitorEventLogger::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->visitorTagsToClean as $entry) {
            $this->visitorTagManager->removeTag($entry['visitorId'], $entry['tag']);
        }
        $this->visitorTagsToClean = [];

        foreach ($this->campaignIds as $campaignId) {
            $campaignFactory = self::$objectManager->get(CampaignFactory::class);
            $campaignResource = self::$objectManager->get(CampaignResource::class);
            $campaign = $campaignFactory->create();
            $campaignResource->load($campaign, $campaignId);
            if ($campaign->getEntityId()) {
                $campaignResource->delete($campaign);
            }
        }
        $this->campaignIds = [];

        $this->deletePendingPopupsForVisitor($this->cleanupVisitorId);
    }

    private function deletePendingPopupsForVisitor(?string $visitorId): void
    {
        if ($visitorId === null) {
            return;
        }

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $connection->delete($resourceConnection->getTableName('ordo_pending_popup'), ['visitor_id = ?' => $visitorId]);
    }

    private function countPendingPopupsForVisitor(string $visitorId): int
    {
        $collectionFactory = self::$objectManager->get(PendingPopupCollectionFactory::class);
        $collection = $collectionFactory->create();
        $collection->addFieldToFilter('visitor_id', $visitorId);

        return $collection->getSize();
    }

    private function createCampaign(string $triggerEvent): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test visitor campaign ' . uniqid('', true));
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

    /**
     * VisitorEventLogger::log() no longer aggregates synchronously — it publishes onto
     * ordo.automation.visitor.aggregate (see Model\Queue\VisitorAggregationPublisher) and
     * returns immediately, the same move CampaignDispatcher's callers made earlier. So proving
     * "anonymous events actually turn into a tag" now means proving the whole
     * publish -> queue -> consumer -> VisitorAggregator chain, the same way
     * CampaignQueueWiringTest proves campaign dispatch: run the real consumer as a subprocess,
     * not call VisitorAggregator directly.
     */
    public function testAnonymousVisitorEventsAggregateIntoARealVisitorTagThroughTheRealQueue(): void
    {
        $visitorId = 'itest-' . uniqid('', true);
        $this->cleanupVisitorId = $visitorId;
        $categoryId = 'cat-' . uniqid('', true);
        $tag = sprintf('viewed_category_view_%s', $categoryId);

        self::assertFalse($this->visitorTagManager->hasTag($visitorId, $tag));

        // Config::getTrackingViewThreshold() defaults to 3 — three anonymous, never-logged-in
        // events for the same category should cross it exactly. Each call publishes a message;
        // nothing has been consumed yet at this point.
        for ($i = 0; $i < 3; $i++) {
            $this->visitorEventLogger->log($visitorId, 'category_view', $categoryId, null);
        }

        self::assertFalse(
            $this->visitorTagManager->hasTag($visitorId, $tag),
            'sanity check: aggregation must NOT have happened yet — nothing has consumed the queue'
        );

        $this->drainPendingMessages('ordo.automation.visitor.aggregate');

        self::assertTrue(
            $this->visitorTagManager->hasTag($visitorId, $tag),
            'three anonymous category views must aggregate into a real ordo_visitor_tag row, through the real queue, without ever logging in'
        );

        $this->visitorTagsToClean[] = ['visitorId' => $visitorId, 'tag' => $tag];
    }

    /**
     * Same backlog problem CampaignQueueWiringTest documents: other tests in this run (and
     * VisitorEventLogger's other callers) publish onto this same topic, so the exact pending
     * count must be counted and drained, not guessed — see that class's drainPendingMessages()
     * for why a fixed --max-messages either flakes or hangs on this install's DB queue driver.
     */
    private function drainPendingMessages(string $consumerName): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $pendingCount = (int) $connection->fetchOne(
            $connection->select()
                ->from(
                    ['qms' => $resourceConnection->getTableName('queue_message_status')],
                    ['COUNT(*)']
                )
                ->joinInner(
                    ['q' => $resourceConnection->getTableName('queue')],
                    'q.id = qms.queue_id',
                    []
                )
                ->where('q.name = ?', $consumerName)
                ->where('qms.status = ?', 2) // Magento\MysqlMq\Model\QueueManagement::MESSAGE_STATUS_NEW
        );

        self::assertGreaterThan(0, $pendingCount, 'sanity check: our own just-published message(s) must be pending');

        $command = sprintf(
            '%s %s/bin/magento queue:consumers:start %s --max-messages=%d 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(rtrim(BP, '/')),
            escapeshellarg($consumerName),
            $pendingCount
        );

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, 'queue:consumers:start must exit cleanly; output: ' . implode("\n", $output));
    }

    public function testVisitorTagAddedCampaignWithPopupActionCreatesARealPendingPopup(): void
    {
        $visitorId = 'itest-' . uniqid('', true);
        $this->cleanupVisitorId = $visitorId;
        $tag = 'vip-' . uniqid('', true);

        $campaignId = $this->createCampaign('visitor_tag_added');
        $this->addCondition($campaignId, 'visitor_tag', ['tag' => $tag]);
        $this->addAction($campaignId, 'popup', [
            'headline' => 'Welcome back!',
            'body' => 'We saved something for you.',
            'cta_label' => 'Shop now',
            'cta_url' => 'https://example.test/sale',
        ]);
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        // Condition not yet satisfied — dispatch must not create a popup.
        $this->dispatcher->dispatch('visitor_tag_added', ['visitor_id' => $visitorId, 'tag' => $tag]);
        self::assertSame(0, $this->countPendingPopupsForVisitor($visitorId));

        // Give the visitor the tag for real, then dispatch again.
        $this->visitorTagManager->addTag($visitorId, $tag);
        $this->visitorTagsToClean[] = ['visitorId' => $visitorId, 'tag' => $tag];

        $this->dispatcher->dispatch('visitor_tag_added', ['visitor_id' => $visitorId, 'tag' => $tag]);

        $collectionFactory = self::$objectManager->get(PendingPopupCollectionFactory::class);
        $collection = $collectionFactory->create();
        $collection->addFieldToFilter('visitor_id', $visitorId);

        self::assertCount(1, $collection, 'a real ordo_pending_popup row must exist for this visitor_id');

        /** @var PendingPopup $popup */
        $popup = $collection->getFirstItem();
        self::assertNull($popup->getCustomerId());
        self::assertSame($visitorId, $popup->getVisitorId());
        self::assertSame('Welcome back!', $popup->getHeadline());
        self::assertSame('Shop now', $popup->getCtaLabel());
        self::assertNull($popup->getDeliveredAt());
    }

    public function testPendingPopupCanBeClaimedExactlyOnceThroughARealConditionalUpdate(): void
    {
        $visitorId = 'itest-' . uniqid('', true);
        $this->cleanupVisitorId = $visitorId;

        $campaignId = $this->createCampaign('visitor_tag_added');
        $this->addAction($campaignId, 'popup', ['headline' => 'Hello!']);
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        $this->dispatcher->dispatch('visitor_tag_added', ['visitor_id' => $visitorId, 'tag' => 'irrelevant']);

        $collectionFactory = self::$objectManager->get(PendingPopupCollectionFactory::class);
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_pending_popup');

        $collection = $collectionFactory->create();
        $collection->addTargetFilter(null, $visitorId, date('Y-m-d H:i:s'));
        self::assertCount(1, $collection);
        /** @var PendingPopup $popup */
        $popup = $collection->getFirstItem();

        // First claim succeeds against the real table...
        $firstClaim = $connection->update(
            $table,
            ['delivered_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => (int) $popup->getId(), 'delivered_at IS NULL']
        );
        self::assertSame(1, $firstClaim);

        // ...a second attempt at the same row must affect zero rows — proving the real
        // conditional UPDATE (not a mocked one) actually prevents double delivery.
        $secondClaim = $connection->update(
            $table,
            ['delivered_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => (int) $popup->getId(), 'delivered_at IS NULL']
        );
        self::assertSame(0, $secondClaim);

        // And the collection's own delivered_at filter must now correctly exclude it.
        $afterClaim = $collectionFactory->create();
        $afterClaim->addTargetFilter(null, $visitorId, date('Y-m-d H:i:s'));
        self::assertCount(0, $afterClaim);
    }
}
