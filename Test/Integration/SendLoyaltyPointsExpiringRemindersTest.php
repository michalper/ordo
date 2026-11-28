<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Cron\SendLoyaltyPointsExpiringReminders;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for Cron\SendLoyaltyPointsExpiringReminders - same conventions as
 * ScanReviewRequestDueTest: a real ordo_loyalty_points_ledger row inserted directly (the cron
 * only ever reads type/points_delta/created_at/expired_at off it), a real campaign listening on
 * loyalty_points_expiring with a real add_tag action, proving the whole path: ledger row -> cron
 * scan -> dispatch -> action -> a real customer tag.
 *
 * expiry_days=30, expiry_warning_days=5 for this whole class - the due window is therefore
 * (now-30d, now-25d].
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes the
 * customer, campaign (cascades to its trigger/action), ledger row, and reminder-log rows this
 * test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/SendLoyaltyPointsExpiringRemindersTest.php
 */
class SendLoyaltyPointsExpiringRemindersTest extends TestCase
{
    private const string CONFIG_PATH_ENABLED = 'ordo_automation/loyalty_points/enabled';
    private const string CONFIG_PATH_EXPIRY_DAYS = 'ordo_automation/loyalty_points/expiry_days';
    private const string CONFIG_PATH_WARNING_DAYS = 'ordo_automation/loyalty_points/expiry_warning_days';

    private static ObjectManagerInterface $objectManager;

    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $ledgerIds = [];

    /** @var int[] */
    private array $customerIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);

        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        $configWriter = self::$objectManager->get(ConfigWriter::class);
        $configWriter->save(self::CONFIG_PATH_ENABLED, 1, ScopeInterface::SCOPE_STORES, $storeId);
        $configWriter->save(self::CONFIG_PATH_EXPIRY_DAYS, 30, ScopeInterface::SCOPE_STORES, $storeId);
        $configWriter->save(self::CONFIG_PATH_WARNING_DAYS, 5, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        $configWriter = self::$objectManager->get(ConfigWriter::class);
        $configWriter->delete(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORES, $storeId);
        $configWriter->delete(self::CONFIG_PATH_EXPIRY_DAYS, ScopeInterface::SCOPE_STORES, $storeId);
        $configWriter->delete(self::CONFIG_PATH_WARNING_DAYS, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    protected function setUp(): void
    {
        $this->cache = self::$objectManager->get(CacheInterface::class);
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
    }

    protected function tearDown(): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);

        foreach ($this->ledgerIds as $ledgerId) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_loyalty_points_ledger'),
                ['entity_id = ?' => $ledgerId]
            );
            $connection->delete(
                $resourceConnection->getTableName('ordo_loyalty_points_expiring_reminder_log'),
                ['ledger_id = ?' => $ledgerId]
            );
        }
        $this->ledgerIds = [];

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
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        foreach ($this->customerIds as $customerId) {
            try {
                self::$objectManager->get(CustomerRepositoryInterface::class)->deleteById($customerId);
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }
        $this->customerIds = [];
    }

    public function testDispatchesForALedgerRowInsideTheWarningWindow(): void
    {
        $resultTag = 'loyalty-points-expiring-' . uniqid('', true);
        $this->createCampaignListeningOnLoyaltyPointsExpiring($resultTag);

        $customerId = $this->createCustomer();
        // 27 days old - inside the due window (see class docblock: created_at must be strictly
        // less than 30 days old but at least 25 days old), so 27 days ago qualifies.
        $this->createLedgerRow($customerId, 150, daysAgo: 27);

        self::$objectManager->create(SendLoyaltyPointsExpiringReminders::class)->execute();

        self::assertTrue(
            $this->tagManager->hasTag($customerId, $resultTag),
            'An earn row inside the warning window must dispatch the trigger.'
        );
    }

    public function testDoesNotDispatchTwiceForTheSameLedgerRow(): void
    {
        $resultTag = 'loyalty-points-expiring-' . uniqid('', true);
        $this->createCampaignListeningOnLoyaltyPointsExpiring($resultTag);

        $customerId = $this->createCustomer();
        $this->createLedgerRow($customerId, 150, daysAgo: 27);

        self::$objectManager->create(SendLoyaltyPointsExpiringReminders::class)->execute();
        self::assertTrue($this->tagManager->hasTag($customerId, $resultTag));

        $this->tagManager->removeTag($customerId, $resultTag);
        self::$objectManager->create(SendLoyaltyPointsExpiringReminders::class)->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A ledger row already logged as claimed must never be dispatched a second time.'
        );
    }

    public function testDoesNotDispatchForARowNotYetInsideTheWarningWindow(): void
    {
        $resultTag = 'loyalty-points-expiring-' . uniqid('', true);
        $this->createCampaignListeningOnLoyaltyPointsExpiring($resultTag);

        $customerId = $this->createCustomer();
        // Only 10 days old - well inside the 30-day expiry, nowhere near the 5-day warning window.
        $this->createLedgerRow($customerId, 150, daysAgo: 10);

        self::$objectManager->create(SendLoyaltyPointsExpiringReminders::class)->execute();

        self::assertFalse($this->tagManager->hasTag($customerId, $resultTag), 'A too-fresh row is not due yet.');
    }

    public function testDoesNotDispatchForARowAlreadyPastTheExpiryCutoff(): void
    {
        $resultTag = 'loyalty-points-expiring-' . uniqid('', true);
        $this->createCampaignListeningOnLoyaltyPointsExpiring($resultTag);

        $customerId = $this->createCustomer();
        // 35 days old - already past the 30-day expiry cutoff, Cron\ExpireLoyaltyPoints' own job,
        // not a warning.
        $this->createLedgerRow($customerId, 150, daysAgo: 35);

        self::$objectManager->create(SendLoyaltyPointsExpiringReminders::class)->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A row already past the expiry cutoff is ExpireLoyaltyPoints\' job, not a warning.'
        );
    }

    public function testDoesNotDispatchForARowAlreadyExpired(): void
    {
        $resultTag = 'loyalty-points-expiring-' . uniqid('', true);
        $this->createCampaignListeningOnLoyaltyPointsExpiring($resultTag);

        $customerId = $this->createCustomer();
        $ledgerId = $this->createLedgerRow($customerId, 150, daysAgo: 27);

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->update(
            self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_loyalty_points_ledger'),
            ['expired_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => $ledgerId]
        );

        self::$objectManager->create(SendLoyaltyPointsExpiringReminders::class)->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A row already marked expired must never get a warning about expiring.'
        );
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createCampaignListeningOnLoyaltyPointsExpiring(string $resultTag): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test loyalty-points-expiring campaign ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        $trigger = $triggerFactory->create();
        $trigger->setData([
            'campaign_id' => $campaignId,
            'trigger_event' => CampaignTriggerInterface::TRIGGER_LOYALTY_POINTS_EXPIRING,
        ]);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'add_tag',
            'params' => json_encode(['tags' => [$resultTag]]),
            'sort_order' => 0,
        ]);
        $actionResource->save($action);

        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        return $campaignId;
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-loyalty-points-expiring-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Expiring');
        $customer->setLastname('Points');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function createLedgerRow(int $customerId, int $points, int $daysAgo): int
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_loyalty_points_ledger');

        $connection->insert($table, [
            'customer_id' => $customerId,
            'type' => LoyaltyPointsManager::TYPE_EARN,
            'points_delta' => $points,
        ]);
        $ledgerId = (int) $connection->lastInsertId($table);
        $this->ledgerIds[] = $ledgerId;

        $connection->update(
            $table,
            ['created_at' => date('Y-m-d H:i:s', time() - $daysAgo * 86400)],
            ['entity_id = ?' => $ledgerId]
        );

        return $ledgerId;
    }
}
