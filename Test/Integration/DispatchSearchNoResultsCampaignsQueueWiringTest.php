<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Shell;
use Magento\Framework\Shell\CommandRenderer;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Real event -> publish -> queue -> consumer wiring coverage for Observer\
 * DispatchSearchNoResultsCampaigns and the "search_no_results" trigger (docs/adr/0076) - same
 * precedent Test\Integration\DispatchExitIntentCampaignsQueueWiringTest already established for
 * its sibling trigger: only ever exercised with a mocked CampaignDispatchPublisher in Test/Unit,
 * which can't prove etc/events.xml actually wires the real "ordo_visitor_search_no_results" event
 * to this observer, or that Magento's own consumer runner actually resolves queue.xml/
 * queue_consumer.xml to CampaignDispatchConsumer::execute() for it.
 *
 * Two scenarios, unlike exit_intent's single anonymous-only one - this trigger's whole point of
 * difference is that customer_id rides along when the originating request was authenticated:
 *  - anonymous visitor: popup action, same proof action exit_intent's own test uses.
 *  - logged-in customer: add_tag action, since that one needs a real customer_id to land anywhere.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/DispatchSearchNoResultsCampaignsQueueWiringTest.php
 */
class DispatchSearchNoResultsCampaignsQueueWiringTest extends TestCase
{
    private const CONSUMER_NAME = 'ordo.automation.campaign.dispatch';

    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $customerIds = [];

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

    private function createCampaignWithTrigger(string $actionType, array $actionParams): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Search no results queue wiring test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData(['campaign_id' => $campaignId, 'trigger_event' => 'search_no_results']);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => $actionType,
            'params' => json_encode($actionParams),
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

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-search-no-results-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Search');
        $customer->setLastname('NoResults');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
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

    public function testARealSearchNoResultsEventReachesTheConsumerForAnAnonymousVisitor(): void
    {
        $this->visitorId = 'ordo-search-no-results-queue-test-' . uniqid('', true);
        $this->createCampaignWithTrigger('popup', ['headline' => 'Looking for something specific?']);
        self::$objectManager->get(CacheInterface::class)->clean([CampaignDispatcher::CACHE_TAG]);

        self::assertSame(0, $this->countPendingPopupsForVisitor($this->visitorId));

        // Fires the REAL Magento event Controller\Track\Event actually dispatches for an
        // anonymous visitor (no customer_id key at all), through Magento's own event manager -
        // proves etc/events.xml actually wires "ordo_visitor_search_no_results" to
        // DispatchSearchNoResultsCampaigns.
        self::$objectManager->get(EventManagerInterface::class)->dispatch(
            'ordo_visitor_search_no_results',
            ['visitor_id' => $this->visitorId]
        );

        $this->drainPendingMessages();

        self::assertSame(
            1,
            $this->countPendingPopupsForVisitor($this->visitorId),
            'the full observer -> publisher -> queue -> consumer -> dispatcher -> popup action chain must have run'
        );
    }

    public function testARealSearchNoResultsEventCarriesCustomerIdThroughToARealCustomerKeyedAction(): void
    {
        $customerId = $this->createCustomer();
        $tag = 'search-no-results-' . uniqid('', true);
        $this->createCampaignWithTrigger('add_tag', ['tags' => [$tag]]);
        self::$objectManager->get(CacheInterface::class)->clean([CampaignDispatcher::CACHE_TAG]);

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertSame([], $tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag));

        // Unlike exit_intent, this event carries a real customer_id - proving the full chain
        // reaches a customer-keyed action, not just a visitor-keyed one.
        self::$objectManager->get(EventManagerInterface::class)->dispatch(
            'ordo_visitor_search_no_results',
            ['visitor_id' => 'ordo-search-no-results-customer-test-' . uniqid('', true), 'customer_id' => $customerId]
        );

        $this->drainPendingMessages();

        self::assertContains(
            $customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$customerId], $tag),
            'the real add_tag action must have run for this real, authenticated customer'
        );
    }
}
