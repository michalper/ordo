<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\PaymentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendCheckoutAbandonmentReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign;
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
 * Real DI/DB coverage for ADR 0092's checkout_abandoned trigger: a real quote (via
 * CartManagementInterface/CartRepositoryInterface, same pattern
 * SendAbandonedCartFallbackRemindersTest already establishes), a real ordo_visitor_event
 * `checkout_start` row, scanned and dispatched by the real cron class - proving the full raw-SQL
 * join (quote + visitor event + "no order since") a unit test's mocked connection can't.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown()/tearDownAfterClass()
 * delete the customer, product, quote, order, and log rows this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/SendCheckoutAbandonmentRemindersTest.php
 */
class SendCheckoutAbandonmentRemindersTest extends TestCase
{
    private const string SKU = 'ordo-checkout-abandoned-test-product';

    private static ObjectManagerInterface $objectManager;

    private ?int $customerId = null;
    private ?int $quoteId = null;
    private ?int $campaignId = null;

    /** @var int[] */
    private array $orderIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('frontend');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);

        self::createSimpleProduct();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::$objectManager->get(ProductRepositoryInterface::class)->deleteById(self::SKU);
        } catch (\Throwable $e) {
            // Best-effort cleanup only.
        }
    }

    protected function tearDown(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        if ($this->quoteId !== null) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_visitor_event'),
                ['customer_id = ?' => $this->customerId]
            );
            $connection->delete(
                $resourceConnection->getTableName('ordo_checkout_abandoned_reminder_log'),
                ['quote_id = ?' => $this->quoteId]
            );
            try {
                self::$objectManager->get(CartRepositoryInterface::class)->deleteById($this->quoteId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->quoteId = null;
        }

        $orderResource = self::$objectManager->get(OrderResource::class);
        foreach ($this->orderIds as $orderId) {
            try {
                /** @var Order $order */
                $order = self::$objectManager->get(OrderFactory::class)->create();
                $orderResource->load($order, $orderId);
                if ($order->getId()) {
                    $orderResource->delete($order);
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->orderIds = [];

        if ($this->campaignId !== null) {
            /** @var Campaign $campaign */
            $campaignResource = self::$objectManager->get(CampaignResource::class);
            $campaign = self::$objectManager->get(CampaignFactory::class)->create();
            $campaignResource->load($campaign, $this->campaignId);
            if ($campaign->getId()) {
                $campaignResource->delete($campaign);
            }
            $this->campaignId = null;
        }

        if ($this->customerId !== null) {
            try {
                self::$objectManager->get(CustomerRepositoryInterface::class)->deleteById($this->customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->customerId = null;
        }
    }

    public function testDispatchesForAQuoteWithCheckoutStartAndNoOrderSince(): void
    {
        $this->createCampaignWithCheckoutAbandonedTrigger();
        $this->createCustomer();
        $this->createCartForCustomer();
        $this->insertCheckoutStartEvent();
        $this->backdateQuoteUpdatedAt(90);

        $this->runCron();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertContains(
            $this->customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$this->customerId], 'checkout_abandoned_received'),
            'A quote with a checkout_start event and no order since must dispatch.'
        );
    }

    public function testDoesNotDispatchWithoutACheckoutStartEvent(): void
    {
        $this->createCampaignWithCheckoutAbandonedTrigger();
        $this->createCustomer();
        $this->createCartForCustomer();
        $this->backdateQuoteUpdatedAt(90);

        $this->runCron();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertSame(
            [],
            $tagManager->getCustomerIdsWithTagFromSet([$this->customerId], 'checkout_abandoned_received')
        );
    }

    public function testDoesNotDispatchWhenAnOrderWasPlacedSinceTheQuoteStarted(): void
    {
        $this->createCampaignWithCheckoutAbandonedTrigger();
        $this->createCustomer();
        $this->createCartForCustomer();
        $this->insertCheckoutStartEvent();
        $this->backdateQuoteUpdatedAt(90);
        $this->createOrderForCustomer();

        $this->runCron();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertSame(
            [],
            $tagManager->getCustomerIdsWithTagFromSet([$this->customerId], 'checkout_abandoned_received'),
            'An order placed since the quote started must suppress the trigger.'
        );
    }

    public function testASecondRunDoesNotDispatchAgainForTheSameQuote(): void
    {
        $this->createCampaignWithCheckoutAbandonedTrigger();
        $this->createCustomer();
        $this->createCartForCustomer();
        $this->insertCheckoutStartEvent();
        $this->backdateQuoteUpdatedAt(90);

        $this->runCron();

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $claimCountAfterFirstRun = (int) $resourceConnection->getConnection()->fetchOne(
            $resourceConnection->getConnection()->select()
                ->from($resourceConnection->getTableName('ordo_checkout_abandoned_reminder_log'), 'COUNT(*)')
                ->where('quote_id = ?', $this->quoteId)
        );
        self::assertSame(1, $claimCountAfterFirstRun);

        $this->runCron();

        $claimCountAfterSecondRun = (int) $resourceConnection->getConnection()->fetchOne(
            $resourceConnection->getConnection()->select()
                ->from($resourceConnection->getTableName('ordo_checkout_abandoned_reminder_log'), 'COUNT(*)')
                ->where('quote_id = ?', $this->quoteId)
        );
        self::assertSame(1, $claimCountAfterSecondRun, 'A second run must not re-dispatch the same quote.');
    }

    // --- fixture builders ------------------------------------------------------------------

    private static function createSimpleProduct(): void
    {
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $product = self::$objectManager->get(ProductInterfaceFactory::class)->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE);
        $product->setAttributeSetId(4);
        $product->setSku(self::SKU);
        $product->setName('Ordo Checkout Abandoned Test Product');
        $product->setPrice(75.0);
        $product->setWebsiteIds([(int) $storeManager->getWebsite()->getId()]);
        $product->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        $product->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH);
        $product->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

        self::$objectManager->get(ProductRepositoryInterface::class)->save($product);
    }

    private function createCampaignWithCheckoutAbandonedTrigger(): void
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Checkout abandoned trigger test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $this->campaignId = (int) $campaign->getEntityId();

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData(['campaign_id' => $this->campaignId, 'trigger_event' => 'checkout_abandoned']);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $this->campaignId,
            'type' => 'add_tag',
            'params' => json_encode(['tags' => ['checkout_abandoned_received']]),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);
    }

    private function createCustomer(): void
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-checkout-abandoned-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Checkout');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();
    }

    private function createCartForCustomer(): void
    {
        $cartManagement = self::$objectManager->get(CartManagementInterface::class);
        $quoteId = (int) $cartManagement->createEmptyCartForCustomer($this->customerId);

        $cartRepository = self::$objectManager->get(CartRepositoryInterface::class);
        $quote = $cartRepository->get($quoteId);
        $product = self::$objectManager->get(ProductRepositoryInterface::class)->get(self::SKU);
        $quote->addProduct($product, 1);
        $quote->collectTotals();
        $cartRepository->save($quote);

        $this->quoteId = $quoteId;
    }

    private function insertCheckoutStartEvent(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $resourceConnection->getConnection()->insert($resourceConnection->getTableName('ordo_visitor_event'), [
            'visitor_id' => 'ordo-checkout-abandoned-test-' . uniqid('', true),
            'customer_id' => $this->customerId,
            'event_type' => 'checkout_start',
        ]);
    }

    private function backdateQuoteUpdatedAt(int $minutesAgo): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $resourceConnection->getConnection()->update(
            $resourceConnection->getTableName('quote'),
            ['updated_at' => date('Y-m-d H:i:s', time() - $minutesAgo * 60)],
            ['entity_id = ?' => $this->quoteId]
        );
    }

    private function createOrderForCustomer(): void
    {
        /** @var Order $order */
        $order = self::$objectManager->get(OrderFactory::class)->create();
        $order->setCustomerId($this->customerId);
        $order->setState(Order::STATE_COMPLETE);
        $order->setStatus('complete');
        $order->setStoreId(1);
        $order->setGrandTotal(75.0);
        $order->setBaseGrandTotal(75.0);
        $order->setOrderCurrencyCode('USD');
        $order->setBaseCurrencyCode('USD');

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = self::$objectManager->get(PaymentFactory::class)->create();
        $payment->setMethod('checkmo');
        $order->setPayment($payment);

        self::$objectManager->get(OrderResource::class)->save($order);
        $orderId = (int) $order->getId();
        $this->orderIds[] = $orderId;

        // Explicit, safely-later created_at - without this, the order and the quote can land in
        // the same second, making "o.created_at > q.created_at" ambiguous at second precision.
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $resourceConnection->getConnection()->update(
            $resourceConnection->getTableName('sales_order'),
            ['created_at' => date('Y-m-d H:i:s', time() + 60)],
            ['entity_id = ?' => $orderId]
        );
    }

    private function makeEnabledConfig(): Config
    {
        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_automation/checkout_abandonment/enabled', 1, ScopeInterface::SCOPE_STORE);
        $scopeConfig->setValue(
            'ordo_automation/checkout_abandonment/delay_minutes',
            60,
            ScopeInterface::SCOPE_STORE
        );
        $scopeConfig->setValue('ordo_automation/checkout_abandonment/min_subtotal', 0, ScopeInterface::SCOPE_STORE);
        $scopeConfig->setValue('ordo_automation/checkout_abandonment/max_reminders', 1, ScopeInterface::SCOPE_STORE);

        return self::$objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);
    }

    private function flushCampaignCache(): void
    {
        self::$objectManager->get(\Magento\Framework\App\CacheInterface::class)
            ->clean([CampaignDispatcher::CACHE_TAG]);
    }

    private function runCron(): void
    {
        $this->flushCampaignCache();

        $cron = self::$objectManager->create(SendCheckoutAbandonmentReminders::class, [
            'config' => $this->makeEnabledConfig(),
        ]);
        $cron->execute();
    }
}
