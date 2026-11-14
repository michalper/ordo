<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
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
use Ordo\Automation\Cron\SendWishlistAbandonmentReminders;
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
 * Proves Cron\SendWishlistAbandonmentReminders' whole scan-claim-dispatch cycle against real
 * `wishlist`/`wishlist_item` rows (Magento core's own native tables, never previously queried by
 * this module - see that cron's own docblock) and a real `sales_order`/`sales_order_item` join
 * for the per-SKU "did they already buy it" check, same conventions as ScanReviewRequestDueTest:
 * real DI/DB throughout, a real campaign listening on `wishlist_abandoned` with a real `add_tag`
 * action proves the whole path: wishlist item -> cron scan -> dispatch -> action -> a real
 * customer tag.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown()/
 * tearDownAfterClass() delete the customer, campaign (cascades to its trigger/action), wishlist/
 * wishlist_item, sales_order/sales_order_item, reminder-log, and product rows this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/SendWishlistAbandonmentRemindersTest.php
 */
class SendWishlistAbandonmentRemindersTest extends TestCase
{
    private const string SKU = 'ordo-wishlist-abandonment-test-product';
    private const string CONFIG_PATH_ENABLED = 'ordo_automation/wishlist_abandonment/enabled';
    private const string CONFIG_PATH_DELAY_DAYS = 'ordo_automation/wishlist_abandonment/delay_days';

    private static ObjectManagerInterface $objectManager;
    private static int $productId;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $customerIds = [];

    /** @var int[] */
    private array $wishlistIds = [];

    /** @var int[] */
    private array $orderIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);

        self::$productId = self::createSimpleProduct();

        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)
            ->save(self::CONFIG_PATH_ENABLED, 1, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ConfigWriter::class)
            ->save(self::CONFIG_PATH_DELAY_DAYS, 5, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)
            ->delete(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ConfigWriter::class)
            ->delete(self::CONFIG_PATH_DELAY_DAYS, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();

        try {
            self::$objectManager->get(ProductRepositoryInterface::class)->deleteById(self::SKU);
        } catch (\Throwable $e) {
            // Best-effort cleanup only.
        }
    }

    protected function setUp(): void
    {
        $this->dispatcher = self::$objectManager->get(CampaignDispatcher::class);
        $this->cache = self::$objectManager->get(CacheInterface::class);
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
    }

    protected function tearDown(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $orderItemTable = $resourceConnection->getTableName('sales_order_item');
        $orderTable = $resourceConnection->getTableName('sales_order');
        foreach ($this->orderIds as $orderId) {
            $connection->delete($orderItemTable, ['order_id = ?' => $orderId]);
            $connection->delete($orderTable, ['entity_id = ?' => $orderId]);
        }
        $this->orderIds = [];

        $wishlistItemTable = $resourceConnection->getTableName('wishlist_item');
        $wishlistTable = $resourceConnection->getTableName('wishlist');
        foreach ($this->wishlistIds as $wishlistId) {
            $connection->delete($wishlistItemTable, ['wishlist_id = ?' => $wishlistId]);
            $connection->delete($wishlistTable, ['wishlist_id = ?' => $wishlistId]);
        }
        $this->wishlistIds = [];

        $logTable = $resourceConnection->getTableName('ordo_wishlist_abandoned_reminder_log');
        foreach ($this->customerIds as $customerId) {
            $connection->delete($logTable, ['customer_id = ?' => $customerId]);
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
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        foreach ($this->customerIds as $customerId) {
            try {
                self::$objectManager->get(CustomerRepositoryInterface::class)->deleteById($customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->customerIds = [];
    }

    public function testDispatchesWishlistAbandonedForAnItemPastTheDelay(): void
    {
        $resultTag = 'wishlist-abandoned-' . uniqid('', true);
        $this->createCampaignListeningOnWishlistAbandoned($resultTag);

        $customerId = $this->createCustomer();
        // Added 10 days ago - past the 5-day delay this test configures.
        $this->addToWishlist($customerId, daysAgo: 10);

        self::$objectManager->create(SendWishlistAbandonmentReminders::class)->execute();

        self::assertTrue(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A wishlist item past the abandonment delay, with no order since, must dispatch the trigger.'
        );
        self::assertTrue(
            $this->wasLogged($customerId),
            'The customer/sku must be logged as claimed so it is never dispatched twice.'
        );
    }

    public function testDoesNotDispatchTwiceForTheSameCustomerAndSku(): void
    {
        $resultTag = 'wishlist-abandoned-' . uniqid('', true);
        $this->createCampaignListeningOnWishlistAbandoned($resultTag);

        $customerId = $this->createCustomer();
        $this->addToWishlist($customerId, daysAgo: 10);

        self::$objectManager->create(SendWishlistAbandonmentReminders::class)->execute();
        self::assertTrue($this->tagManager->hasTag($customerId, $resultTag));

        $this->tagManager->removeTag($customerId, $resultTag);
        self::$objectManager->create(SendWishlistAbandonmentReminders::class)->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A customer/sku already logged as claimed must never be dispatched a second time.'
        );
    }

    public function testDoesNotDispatchBeforeTheConfiguredDelayHasPassed(): void
    {
        $resultTag = 'wishlist-abandoned-' . uniqid('', true);
        $this->createCampaignListeningOnWishlistAbandoned($resultTag);

        $customerId = $this->createCustomer();
        // Added only 1 day ago - well inside the 5-day delay.
        $this->addToWishlist($customerId, daysAgo: 1);

        self::$objectManager->create(SendWishlistAbandonmentReminders::class)->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A too-recently wishlisted item is not due yet.'
        );
    }

    public function testDoesNotDispatchWhenTheCustomerAlreadyOrderedThatSkuSinceAdding(): void
    {
        $resultTag = 'wishlist-abandoned-' . uniqid('', true);
        $this->createCampaignListeningOnWishlistAbandoned($resultTag);

        $customerId = $this->createCustomer();
        $this->addToWishlist($customerId, daysAgo: 10);
        // Ordered 3 days ago - after the wishlist add, so this is no longer "abandoned".
        $this->createOrderWithItem($customerId, self::SKU, daysAgo: 3);

        self::$objectManager->create(SendWishlistAbandonmentReminders::class)->execute();

        self::assertFalse(
            $this->tagManager->hasTag($customerId, $resultTag),
            'A SKU already ordered since it was wishlisted must not dispatch the trigger.'
        );
    }

    // --- fixture builders ------------------------------------------------------------------

    private static function createSimpleProduct(): int
    {
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $product = self::$objectManager->get(ProductInterfaceFactory::class)->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE);
        $product->setAttributeSetId(4);
        $product->setSku(self::SKU);
        $product->setName('Ordo Wishlist Abandonment Test Product');
        $product->setPrice(42.0);
        $product->setWebsiteIds([(int) $storeManager->getWebsite()->getId()]);
        $product->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        $product->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH);
        $product->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

        $saved = self::$objectManager->get(ProductRepositoryInterface::class)->save($product);

        return (int) $saved->getId();
    }

    private function createCampaignListeningOnWishlistAbandoned(string $resultTag): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test wishlist-abandonment campaign ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        $trigger = $triggerFactory->create();
        $trigger->setData([
            'campaign_id' => $campaignId,
            'trigger_event' => CampaignTriggerInterface::TRIGGER_WISHLIST_ABANDONED,
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
        $customer->setEmail('ordo-wishlist-abandonment-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Wishlist');
        $customer->setLastname('Abandonment');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function addToWishlist(int $customerId, int $daysAgo): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $wishlistTable = $resourceConnection->getTableName('wishlist');
        $connection->insert($wishlistTable, [
            'customer_id' => $customerId,
            'shared' => 0,
        ]);
        $wishlistId = (int) $connection->lastInsertId($wishlistTable);
        $this->wishlistIds[] = $wishlistId;

        $wishlistItemTable = $resourceConnection->getTableName('wishlist_item');
        $connection->insert($wishlistItemTable, [
            'wishlist_id' => $wishlistId,
            'product_id' => self::$productId,
            'store_id' => $storeId,
            'added_at' => date('Y-m-d H:i:s', time() - $daysAgo * 86400),
            'qty' => 1,
        ]);
    }

    private function createOrderWithItem(int $customerId, string $sku, int $daysAgo): int
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $orderTable = $resourceConnection->getTableName('sales_order');
        $connection->insert($orderTable, [
            'customer_id' => $customerId,
            'grand_total' => 42.0,
            'state' => 'complete',
            'status' => 'complete',
            'store_id' => $storeId,
            'created_at' => date('Y-m-d H:i:s', time() - $daysAgo * 86400),
            'increment_id' => 'ORDOWLTEST-' . uniqid('', true),
        ]);
        $orderId = (int) $connection->lastInsertId($orderTable);
        $this->orderIds[] = $orderId;

        $orderItemTable = $resourceConnection->getTableName('sales_order_item');
        $connection->insert($orderItemTable, [
            'order_id' => $orderId,
            'sku' => $sku,
            'qty_ordered' => 1,
            'price' => 42.0,
        ]);

        return $orderId;
    }

    private function wasLogged(int $customerId): bool
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_wishlist_abandoned_reminder_log');

        $count = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])->where('customer_id = ?', $customerId)
        );

        return $count > 0;
    }
}
