<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Shell;
use Magento\Framework\Shell\CommandRenderer;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Real event -> publish -> queue -> consumer wiring coverage for Observer\
 * DispatchExitIntentCampaigns and the "exit_intent" trigger - found via the 2026-11-14
 * full-sweep audit (Test/Mftf/SCENARIOS.md §42): only ever exercised with a mocked
 * CampaignDispatchPublisher in Test/Unit, which can't prove etc/events.xml actually wires the
 * real "ordo_visitor_exit_intent" event to this observer, or that Magento's own consumer runner
 * actually resolves queue.xml/queue_consumer.xml to CampaignDispatchConsumer::execute() for it.
 *
 * Follows Test/Integration/CampaignQueueWiringTest.php's own precedent exactly (real
 * EventManagerInterface::dispatch() of the real event name, then a real
 * `bin/magento queue:consumers:start` subprocess to drain it - this install has no RabbitMQ,
 * DB queue driver only, see AGENTS.md) - same consumer ("ordo.automation.campaign.dispatch")
 * and the same FIFO-backlog counting technique, since every other test in this suite that fires
 * a real Magento event shares this one queue.
 *
 * exit_intent is anonymous-only context (visitor_id, no customer_id - see that observer's own
 * docblock), so the proof action here is "popup" against a real ordo_pending_popup row, the same
 * pattern Test/Integration/CampaignVisitorPopupScenarioTest.php already establishes for the
 * sibling visitor_tag_added trigger.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/DispatchExitIntentCampaignsQueueWiringTest.php
 */
class DispatchExitIntentCampaignsQueueWiringTest extends TestCase
{
    private const CONSUMER_NAME = 'ordo.automation.campaign.dispatch';

    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $campaignIds = [];

    private ?string $visitorId = null;

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
        if ($this->visitorId !== null) {
            $resourceConnection = self::$objectManager->get(ResourceConnection::class);
            $resourceConnection->getConnection()->delete(
                $resourceConnection->getTableName('ordo_pending_popup'),
                ['visitor_id = ?' => $this->visitorId]
            );
            $this->visitorId = null;
        }

        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        foreach ($this->campaignIds as $campaignId) {
            $campaign = $campaignFactory->create();
            $campaignResource->load($campaign, $campaignId);
            if ($campaign->getEntityId()) {
                $campaignResource->delete($campaign);
            }
        }
        $this->campaignIds = [];
    }

    private function createCampaignWithExitIntentPopup(): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Exit intent queue wiring test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData(['campaign_id' => $campaignId, 'trigger_event' => 'exit_intent']);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'popup',
            'params' => json_encode(['headline' => 'Wait, don\'t go!']),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);

        return $campaignId;
    }

    private function countPendingPopupsForVisitor(string $visitorId): int
    {
        $collectionFactory = self::$objectManager->get(PendingPopupCollectionFactory::class);
        $collection = $collectionFactory->create();
        $collection->addFieldToFilter('visitor_id', $visitorId);

        return $collection->getSize();
    }

    /**
     * Same FIFO-backlog reasoning as CampaignQueueWiringTest::drainPendingMessages() - every
     * other test in this suite that fires a real Magento event publishes onto this same queue,
     * so a fixed --max-messages guess is either flaky or hangs (consumers_wait_for_messages=1).
     * Counting the exact pending backlog right before running avoids both failure modes.
     */
    private function drainPendingMessages(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $pendingCount = (int) $connection->fetchOne(
            $connection->select()
                ->from(['qms' => $resourceConnection->getTableName('queue_message_status')], ['COUNT(*)'])
                ->joinInner(
                    ['q' => $resourceConnection->getTableName('queue')],
                    'q.id = qms.queue_id',
                    []
                )
                ->where('q.name = ?', self::CONSUMER_NAME)
                // Magento\MysqlMq\Model\QueueManagement::MESSAGE_STATUS_NEW - not a stable public
                // API class, same reasoning CampaignQueueWiringTest inlines this value for.
                ->where('qms.status = ?', 2)
        );

        self::assertGreaterThan(0, $pendingCount, 'sanity check: our own just-published message must be pending');

        (new Shell(new CommandRenderer()))->execute(
            '%s %s/bin/magento queue:consumers:start %s --max-messages=%s',
            [PHP_BINARY, rtrim(BP, '/'), self::CONSUMER_NAME, (string) $pendingCount]
        );
    }

    public function testARealExitIntentEventReachesTheConsumerThroughTheRealQueueAndCreatesAPendingPopup(): void
    {
        $this->visitorId = 'ordo-exit-intent-queue-test-' . uniqid('', true);
        $this->createCampaignWithExitIntentPopup();
        self::$objectManager->get(CacheInterface::class)->clean([CampaignDispatcher::CACHE_TAG]);

        self::assertSame(
            0,
            $this->countPendingPopupsForVisitor($this->visitorId),
            'sanity check: no pending popup must exist before the event fires'
        );

        // Fires the REAL Magento event Controller\Track\Event actually dispatches, through
        // Magento's own event manager (not calling the observer class directly) - proves
        // etc/events.xml actually wires "ordo_visitor_exit_intent" to DispatchExitIntentCampaigns.
        self::$objectManager->get(EventManagerInterface::class)->dispatch(
            'ordo_visitor_exit_intent',
            ['visitor_id' => $this->visitorId]
        );

        // At this point the observer has published onto the real (DB-driver) queue - nothing has
        // consumed it yet. Running the real consumer subprocess proves queue.xml/
        // queue_consumer.xml/communication.xml actually resolve to CampaignDispatchConsumer::
        // execute(), which then runs the real ConditionPool/ActionPool against this trigger.
        $this->drainPendingMessages();

        self::assertSame(
            1,
            $this->countPendingPopupsForVisitor($this->visitorId),
            'the full observer -> publisher -> queue -> consumer -> dispatcher -> popup action chain must have run'
        );
    }
}
