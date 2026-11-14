<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\Email\MessageEngagementResolver;
use Ordo\Automation\Model\MessageLogEvent;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/database coverage for the `message_engaged` campaign condition
 * (Model\Campaign\Condition\MessageEngaged / Model\Email\MessageEngagementResolver) - found via
 * the 2026-11-14 full-sweep audit (Test/Mftf/SCENARIOS.md §42): zero footprint anywhere, only
 * Test/Unit (mocked ResourceConnection, which can't prove the real ordo_message_log_event ->
 * ordo_message_log join actually resolves). Same "real engine condition, zero DB-backed proof"
 * shape this audit already closed for `churn_risk_at_least` -
 * Test/Integration/ChurnRiskAtLeastConditionScenarioTest.php is the direct sibling pattern this
 * follows.
 *
 * Neither ordo_message_log.campaign_id/customer_id nor ordo_message_log_event.message_log_id has
 * a foreign key back to ordo_campaign/customer_entity (both tables' own db_schema.xml comments
 * say so explicitly - a historical log row must survive the campaign/customer being deleted), so
 * message log/event rows are inserted directly, the same minimal-fixture reasoning
 * Test/Integration/CampaignComparisonGridCollectionTest.php's own logOutcome() already gives.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes every
 * campaign/tag/message-log/event row this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/MessageEngagedConditionScenarioTest.php
 */
class MessageEngagedConditionScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $messageLogIds = [];

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

        if ($this->messageLogIds !== []) {
            $resourceConnection = self::$objectManager->get(ResourceConnection::class);
            $connection = $resourceConnection->getConnection();
            // ordo_message_log_event cascades on delete (see its own FK), so deleting the parent
            // row is enough to clean up both tables.
            $connection->delete(
                $resourceConnection->getTableName('ordo_message_log'),
                ['entity_id IN (?)' => $this->messageLogIds]
            );
        }
        $this->messageLogIds = [];
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createCampaign(string $triggerEvent, int $campaignIdForCondition, string $eventType, int $withinDays): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Message engaged condition test ' . uniqid('', true));
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
            'type' => 'message_engaged',
            'params' => json_encode([
                'campaign_id' => $campaignIdForCondition,
                'engagement_type' => $eventType,
                'engagement_within_days' => $withinDays,
            ]),
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

    /**
     * $daysAgo backdates created_at via a direct UPDATE (same technique
     * ChurnRiskAtLeastConditionScenarioTest's own createOrderDaysAgo() already establishes).
     */
    private function logMessageEngagement(int $sentCampaignId, int $customerId, string $eventType, int $daysAgo): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $connection->insert($resourceConnection->getTableName('ordo_message_log'), [
            'channel' => 'email',
            'customer_id' => $customerId,
            'to_address' => 'customer-' . $customerId . '@example.test',
            'provider_message_id' => 'ordo-message-engaged-test-' . uniqid('', true),
            'status' => 'delivered',
            'campaign_id' => $sentCampaignId,
        ]);
        $messageLogId = (int) $connection->lastInsertId($resourceConnection->getTableName('ordo_message_log'));
        $this->messageLogIds[] = $messageLogId;

        $connection->insert($resourceConnection->getTableName('ordo_message_log_event'), [
            'message_log_id' => $messageLogId,
            'event_type' => $eventType,
        ]);
        $connection->update(
            $resourceConnection->getTableName('ordo_message_log_event'),
            ['created_at' => date('Y-m-d H:i:s', (int) strtotime("-{$daysAgo} days"))],
            ['message_log_id = ?' => $messageLogId]
        );
    }

    // --- tests -------------------------------------------------------------------------------

    public function testCustomerWhoOpenedTheSpecificCampaignWithinTheWindowGetsTheAction(): void
    {
        $sentCampaignId = 900001;
        $customerId = 777001;
        $this->logMessageEngagement($sentCampaignId, $customerId, MessageLogEvent::TYPE_OPENED, daysAgo: 2);

        $triggerEvent = 'test_message_engaged_hit_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, $sentCampaignId, MessageLogEvent::TYPE_OPENED, 14);
        $tag = 'message-engaged-hit-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$tag]]);
        $this->flushCampaignCache();
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertContains(
            $customerId,
            $this->tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag),
            'A customer who really opened this exact campaign within the window must get the action.'
        );
    }

    public function testEngagementOutsideTheWindowDoesNotGetTheAction(): void
    {
        $sentCampaignId = 900002;
        $customerId = 777002;
        $this->logMessageEngagement($sentCampaignId, $customerId, MessageLogEvent::TYPE_OPENED, daysAgo: 20);

        $triggerEvent = 'test_message_engaged_stale_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, $sentCampaignId, MessageLogEvent::TYPE_OPENED, 14);
        $tag = 'message-engaged-stale-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$tag]]);
        $this->flushCampaignCache();
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertSame(
            [],
            $this->tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag),
            'An open event 20 days ago must not satisfy a 14-day window.'
        );
    }

    public function testClickedEventDoesNotSatisfyAConditionLookingForOpened(): void
    {
        $sentCampaignId = 900003;
        $customerId = 777003;
        $this->logMessageEngagement($sentCampaignId, $customerId, MessageLogEvent::TYPE_CLICKED, daysAgo: 1);

        $triggerEvent = 'test_message_engaged_wrong_type_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, $sentCampaignId, MessageLogEvent::TYPE_OPENED, 14);
        $tag = 'message-engaged-wrong-type-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$tag]]);
        $this->flushCampaignCache();
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertSame(
            [],
            $this->tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag),
            'A clicked event must not satisfy a condition specifically looking for opened.'
        );
    }

    public function testResolveCustomerIdsReturnsOnlyCustomersWhoEngagedWithTheExactCampaignAndType(): void
    {
        $sentCampaignId = 900004;
        $otherCampaignId = 900005;
        $matchingCustomerId = 777004;
        $otherCampaignCustomerId = 777005;

        $this->logMessageEngagement($sentCampaignId, $matchingCustomerId, MessageLogEvent::TYPE_OPENED, daysAgo: 1);
        // A real engagement that must NOT appear: right event type, wrong campaign.
        $this->logMessageEngagement($otherCampaignId, $otherCampaignCustomerId, MessageLogEvent::TYPE_OPENED, daysAgo: 1);

        /** @var MessageEngagementResolver $resolver */
        $resolver = self::$objectManager->get(MessageEngagementResolver::class);
        $customerIds = $resolver->getCustomerIdsWithEngagement($sentCampaignId, MessageLogEvent::TYPE_OPENED, 14);

        self::assertContains($matchingCustomerId, $customerIds);
        self::assertNotContains($otherCampaignCustomerId, $customerIds);
    }
}
