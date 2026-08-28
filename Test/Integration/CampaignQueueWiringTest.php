<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Proves the piece CampaignDispatchScenarioTest deliberately skips: that a REAL Magento event
 * actually reaches our observer (etc/events.xml), that the observer actually publishes onto
 * the real message queue (etc/communication.xml, etc/queue*.xml), and that Magento's own
 * consumer runner actually resolves our queue_consumer.xml/queue.xml config to
 * CampaignDispatchConsumer::execute() and runs it — end to end, no mocks, no shortcuts through
 * CampaignDispatcher directly.
 *
 * This install has no RabbitMQ (DB queue driver only, see AGENTS.md) — running the real
 * `queue:consumers:start` CLI as a subprocess is the only way to exercise this wiring exactly
 * as production cron would.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/CampaignQueueWiringTest.php
 */
class CampaignQueueWiringTest extends TestCase
{
    private const CONSUMER_NAME = 'ordo.automation.campaign.dispatch';

    private static ObjectManagerInterface $objectManager;

    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var array{customerId: int, tag: string}[] */
    private array $tagsToClean = [];

    private ?int $customerId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        // See CampaignDispatchScenarioTest::setUpBeforeClass() — required for customer cleanup.
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function setUp(): void
    {
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tagsToClean as $entry) {
            $this->tagManager->removeTag($entry['customerId'], $entry['tag']);
        }
        $this->tagsToClean = [];

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

        if ($this->customerId !== null) {
            try {
                self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
                    ->deleteById($this->customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->customerId = null;
        }
    }

    public function testRealCustomerRegisteredEventReachesTheConsumerThroughTheRealQueue(): void
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);

        $email = 'ordo-automation-queue-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Queue');
        $customer->setLastname('Wiring');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();

        // trigger_event here MUST be the real event name — unlike CampaignDispatchScenarioTest,
        // this test exercises the real Magento event -> real observer -> real etc/events.xml
        // wiring, not a direct dispatch() call, so it has to match what
        // DispatchCustomerRegisteredCampaigns actually publishes.
        $triggerEvent = 'customer_registered';

        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaign = $campaignFactory->create();
        $campaign->setName('Queue wiring test campaign ' . uniqid('', true));
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

        $tag = 'queue-wiring-' . uniqid('', true);
        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'add_tag',
            'params' => json_encode(['tag' => $tag]),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);

        self::$objectManager->get(CacheInterface::class)->clean([CampaignDispatcher::CACHE_TAG]);

        self::assertFalse($this->tagManager->hasTag($this->customerId, $tag), 'sanity check: tag must not exist before the event fires');

        // Fire the REAL Magento event — this is what Magento_Customer dispatches on real
        // registration; going through Magento's own event manager (not calling the observer
        // class directly) proves etc/events.xml actually wires it to our observer.
        self::$objectManager->get(EventManagerInterface::class)->dispatch(
            'customer_register_success',
            ['customer' => $saved]
        );

        // At this point the observer has published a message onto the real (DB-driver) queue —
        // nothing has consumed it yet. Run Magento's actual consumer runner as a subprocess,
        // exactly like the cron_consumers_runner config would in production (see AGENTS.md),
        // to prove queue.xml/queue_consumer.xml/queue_topology.xml/communication.xml actually
        // resolve to CampaignDispatchConsumer::execute().
        $this->drainPendingMessages();

        self::assertTrue(
            $this->tagManager->hasTag($this->customerId, $tag),
            'the full observer -> publisher -> queue -> consumer -> dispatcher chain must have run'
        );

        $this->tagsToClean[] = ['customerId' => $this->customerId, 'tag' => $tag];
    }

    /**
     * Every other test in this suite (and CampaignDispatchScenarioTest, which calls the real,
     * non-mocked CustomerTagManager) fires real Magento events that real observers in this
     * module publish onto this SAME queue — e.g. every add_tag action publishes a real
     * "tag_added" message. Those pile up as a legitimate backlog ahead of this test's message
     * in FIFO order, so a fixed --max-messages guess is either flaky (too low: this test's own
     * message never gets reached before the budget runs out) or hangs forever (too high: this
     * install's DB queue driver has consumers_wait_for_messages=1, so once the real backlog is
     * exhausted the consumer blocks waiting for messages that will never come, instead of
     * exiting). Counting the exact pending backlog right before running and passing that
     * (+1 for this test's own message, which is already enqueued by this point) as
     * --max-messages avoids both failure modes.
     */
    private function drainPendingMessages(): void
    {
        $resourceConnection = self::$objectManager->get(\Magento\Framework\App\ResourceConnection::class);
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
                ->where('q.name = ?', self::CONSUMER_NAME)
                // Magento\MysqlMq\Model\QueueManagement::MESSAGE_STATUS_NEW — not yet consumed.
                // That class isn't part of a stable public API, so the value is inlined rather
                // than depending on a Magento_MysqlMq class directly from this module's tests.
                ->where('qms.status = ?', 2)
        );

        self::assertGreaterThan(0, $pendingCount, 'sanity check: our own just-published message must be pending');

        $command = sprintf(
            '%s %s/bin/magento queue:consumers:start %s --max-messages=%d 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(rtrim(BP, '/')),
            escapeshellarg(self::CONSUMER_NAME),
            $pendingCount
        );

        exec($command, $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            'queue:consumers:start must exit cleanly; output: ' . implode("\n", $output)
        );
    }
}
