<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/database coverage for docs/adr/0057's no-send holdout group - per that ADR's own
 * "Consequences" section, this feature "was implemented and verified with this repo's own
 * standalone unit-test toolchain only... the schema migration, admin form rendering, and the
 * dispatcher's real DB-backed behavior are NOT verified against a running install." This suite
 * closes exactly that gap: a real ordo_campaign.holdout_percent column read off a real campaign
 * row (through CampaignDispatcher's own cache, the same path dispatch() always uses), a real
 * Model\Campaign\HoldoutSelector bucket decision for a campaign id only known after a real
 * insert, and real ordo_campaign_outcome_log rows partitioned by is_holdout for
 * getStats()/getHoldoutStats().
 *
 * holdout_percent is deliberately set to the extremes (0 or 100) throughout, not some value in
 * between - HoldoutSelector::isHeldOut()'s bucket is a deterministic crc32 of
 * (campaign id, customer id), and the campaign id doesn't exist until after this test inserts the
 * row, so there is no way to pre-compute "a customer landing in a 30% bucket" without
 * reimplementing the algorithm here; 0/100 make every assertion unconditional instead.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes every
 * campaign/customer/outcome-log row this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/CampaignHoldoutGroupScenarioTest.php
 */
class CampaignHoldoutGroupScenarioTest extends TestCase
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

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $outcomeTable = $resourceConnection->getTableName('ordo_campaign_outcome_log');
        foreach ($this->campaignIds as $campaignId) {
            $resourceConnection->getConnection()->delete($outcomeTable, ['campaign_id = ?' => $campaignId]);
        }

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

    public function testFullyHeldOutCustomerGetsNoActionButIsLoggedAsHoldout(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_holdout_full_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, 100);
        $neverTag = 'holdout-never-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$neverTag]]);
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $neverTag),
            'holdout_percent=100 must hold out every customer - not even add_tag should run'
        );

        $outcomeRows = $this->loadOutcomeRows($campaignId);
        self::assertCount(1, $outcomeRows, 'exactly one ordo_campaign_outcome_log row must be written for a held-out dispatch');
        self::assertSame(1, (int) $outcomeRows[0]['is_holdout']);
        self::assertNull($outcomeRows[0]['variant'], 'a held-out customer was never routed into a split variant');

        $logger = self::$objectManager->get(CampaignOutcomeLogger::class);
        // getHoldoutStats()/getStats() key their returned arrays by real campaign_id, not a
        // sequential 0-index - unlike getStats() (built with $stats[] = ...), getHoldoutStats()
        // itself is $stats[$campaignId] = [...].
        $holdoutStats = $logger->getHoldoutStats($campaignId);
        self::assertCount(1, $holdoutStats);
        self::assertSame(1, $holdoutStats[$campaignId]['holdout_count']);

        self::assertSame([], $logger->getStats($campaignId), 'getStats() must never count a held-out customer as sent');
    }

    public function testZeroPercentHoldoutNeverHoldsAnyoneOut(): void
    {
        $customerId = $this->createCustomer();

        $triggerEvent = 'test_holdout_zero_' . uniqid('', true);
        // holdout_percent omitted -> defaults to 0/null, the backward-compatible "off" state.
        $campaignId = $this->createCampaign($triggerEvent, 0);
        $tag = 'holdout-always-runs-' . uniqid('', true);
        $this->addAction($campaignId, 'add_tag', ['tags' => [$tag]]);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];
        $this->flushCampaignCache();

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        self::assertTrue($this->tagManager->hasTag($customerId, $tag), 'holdout_percent=0 must never hold anyone out');
        self::assertSame([], $this->loadOutcomeRows($campaignId), 'nothing is held out, so no outcome row is written for this feature at all');
    }

    /**
     * ADR 0057: holdout is customer-only - a dispatch context with no resolvable customer_id
     * (e.g. an anonymous visitor trigger) has no stable identity to hold out AS, so the check is
     * skipped entirely and the action runs normally, same as holdout_percent=0 would.
     */
    public function testHoldoutIsSkippedWithoutACustomerIdInContext(): void
    {
        $triggerEvent = 'test_holdout_no_customer_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, 100);
        $this->addAction($campaignId, 'popup', ['headline' => 'Still here?']);
        $this->flushCampaignCache();

        $visitorId = 'v-' . uniqid('', true);

        // Must not throw, and (more importantly) must not log a holdout row keyed to no
        // customer - there is nothing to hold out. The popup action must still run normally,
        // the same as if holdout_percent were 0.
        $this->dispatcher->dispatch($triggerEvent, ['visitor_id' => $visitorId]);

        self::assertSame([], $this->loadOutcomeRows($campaignId));

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $popupTable = $resourceConnection->getTableName('ordo_pending_popup');
        $popupCount = $connection->fetchOne(
            $connection->select()->from($popupTable, 'COUNT(*)')->where('visitor_id = ?', $visitorId)
        );
        self::assertSame('1', (string) $popupCount, 'without a customer_id, holdout must be skipped entirely and the popup action must run');

        $connection->delete($popupTable, ['visitor_id = ?' => $visitorId]);
    }

    /**
     * getStats()/getHoldoutStats() genuinely partition the same table by is_holdout - proven
     * against real mixed rows (one logSent(), one logHeldOut()) rather than trusting the SQL
     * `WHERE is_holdout = ?` filter by inspection alone.
     */
    public function testGetStatsAndGetHoldoutStatsPartitionTheSameTable(): void
    {
        $customerId = $this->createCustomer();
        $triggerEvent = 'test_holdout_partition_' . uniqid('', true);
        $campaignId = $this->createCampaign($triggerEvent, 0);
        $this->flushCampaignCache();

        $logger = self::$objectManager->get(CampaignOutcomeLogger::class);
        $logger->logSent($campaignId, null, $customerId);
        $logger->logHeldOut($campaignId, $customerId);

        $stats = $logger->getStats($campaignId);
        self::assertCount(1, $stats);
        self::assertSame(1, $stats[0]['sent']);

        $holdoutStats = $logger->getHoldoutStats($campaignId);
        self::assertCount(1, $holdoutStats);
        self::assertSame(1, $holdoutStats[$campaignId]['holdout_count']);
    }

    private function loadOutcomeRows(int $campaignId): array
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_campaign_outcome_log');

        return $connection->fetchAll(
            $connection->select()->from($table)->where('campaign_id = ?', $campaignId)
        );
    }

    private function createCampaign(string $triggerEvent, int $holdoutPercent): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Holdout integration test campaign ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaign->setHoldoutPercent($holdoutPercent);
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

    private function addAction(int $campaignId, string $type, array $params, int $sortOrder = 0): void
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

        $email = 'ordo-holdout-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Holdout');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());

        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }
}
