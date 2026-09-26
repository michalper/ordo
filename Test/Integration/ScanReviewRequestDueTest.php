<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

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
use Ordo\Automation\Cron\ScanReviewRequestDue;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use PHPUnit\Framework\TestCase;

/**
 * Closes one of the five remaining SCENARIOS.md gaps: Cron\ScanReviewRequestDue /
 * the review_request_due trigger were unit-tested only, never proven against a real completed
 * `sales_order` row and a real campaign dispatch. Real DI/DB throughout, same conventions as
 * SegmentAndRfmScenarioTest - a real order row is inserted directly into sales_order (the cron
 * only ever reads customer_id/status/created_at from it, same reasoning that file's own docblock
 * gives for not driving a full quote-to-order checkout flow), then a real campaign listening on
 * `review_request_due` with a real `add_tag` action proves the whole path: order -> cron scan ->
 * dispatch -> action -> a real customer tag.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes the
 * customer, campaign (cascades to its trigger/action), order, and review-request-log rows this
 * test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/ScanReviewRequestDueTest.php
 */
class ScanReviewRequestDueTest extends TestCase
{
    private const string CONFIG_PATH_ENABLED = 'ordo_automation/review_request/enabled';
    private const string CONFIG_PATH_DELAY_DAYS = 'ordo_automation/review_request/delay_days';

    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $orderIds = [];

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
        self::$objectManager->get(ConfigWriter::class)->save(self::CONFIG_PATH_ENABLED, 1, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ConfigWriter::class)->save(self::CONFIG_PATH_DELAY_DAYS, 5, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)->delete(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ConfigWriter::class)->delete(self::CONFIG_PATH_DELAY_DAYS, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    protected function setUp(): void
    {
        $this->dispatcher = self::$objectManager->get(CampaignDispatcher::class);
        $this->cache = self::$objectManager->get(CacheInterface::class);
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
    }

    protected function tearDown(): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();

        $orderTable = self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order');
        foreach ($this->orderIds as $orderId) {
            $connection->delete($orderTable, ['entity_id = ?' => $orderId]);
        }
        $logTable = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_review_request_log');
        foreach ($this->orderIds as $orderId) {
            $connection->delete($logTable, ['order_id = ?' => $orderId]);
        }
        $this->orderIds = [];

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
                self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
                    ->deleteById($customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->customerIds = [];
    }

    public function testDispatchesReviewRequestDueForACompletedOrderPastTheDelay(): void
    {
        $resultTag = 'review-request-due-' . uniqid('', true);
        $campaignId = $this->createCampaignListeningOnReviewRequestDue($resultTag);

        $customerId = $this->createCustomer();
        // Completed 10 days ago - past the 5-day delay this test configures.
        $orderId = $this->createOrder($customerId, daysAgo: 10);

        self::$objectManager->create(ScanReviewRequestDue::class)->execute();

        self::assertTrue(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A completed order past the review-request delay must dispatch the trigger.'
        );

        $logged = $this->wasLogged($orderId);
        self::assertTrue($logged, 'The order must be logged as claimed so it is never dispatched twice.');
    }

    public function testDoesNotDispatchTwiceForTheSameOrder(): void
    {
        $resultTag = 'review-request-due-' . uniqid('', true);
        $this->createCampaignListeningOnReviewRequestDue($resultTag);

        $customerId = $this->createCustomer();
        $orderId = $this->createOrder($customerId, daysAgo: 10);

        self::$objectManager->create(ScanReviewRequestDue::class)->execute();
        self::assertTrue($this->tagManager->hasTag($customerId, $resultTag));

        $this->tagManager->removeTag($customerId, $resultTag);
        self::$objectManager->create(ScanReviewRequestDue::class)->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $resultTag),
            'An order already logged as claimed must never be dispatched a second time.'
        );
    }

    public function testDoesNotDispatchBeforeTheConfiguredDelayHasPassed(): void
    {
        $resultTag = 'review-request-due-' . uniqid('', true);
        $this->createCampaignListeningOnReviewRequestDue($resultTag);

        $customerId = $this->createCustomer();
        // Completed only 1 day ago - well inside the 5-day delay.
        $this->createOrder($customerId, daysAgo: 1);

        self::$objectManager->create(ScanReviewRequestDue::class)->execute();

        self::assertFalse($this->tagManager->hasTag($customerId, $resultTag), 'A too-recent order is not due yet.');
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createCampaignListeningOnReviewRequestDue(string $resultTag): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test review-request campaign ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        $trigger = $triggerFactory->create();
        $trigger->setData([
            'campaign_id' => $campaignId,
            'trigger_event' => CampaignTriggerInterface::TRIGGER_REVIEW_REQUEST_DUE,
        ]);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'add_tag',
            'params' => json_encode(['tag' => $resultTag]),
            'sort_order' => 0,
        ]);
        $actionResource->save($action);

        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        return $campaignId;
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-review-request-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Review');
        $customer->setLastname('Request');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function createOrder(int $customerId, int $daysAgo): int
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order');
        $storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $connection->insert($table, [
            'customer_id' => $customerId,
            'grand_total' => 100.0,
            'state' => 'complete',
            'status' => 'complete',
            'store_id' => $storeId,
            'created_at' => date('Y-m-d H:i:s', time() - $daysAgo * 86400),
            'increment_id' => 'ORDOTEST-' . uniqid('', true),
        ]);

        $orderId = (int) $connection->lastInsertId($table);
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function wasLogged(int $orderId): bool
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_review_request_log');

        $count = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])->where('order_id = ?', $orderId)
        );

        return $count > 0;
    }
}
