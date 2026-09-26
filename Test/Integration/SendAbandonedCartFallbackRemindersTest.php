<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendAbandonedCartFallbackReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendSms;
use Ordo\Automation\Model\Campaign\Action\SendWhatsApp;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;
use PHPUnit\Framework\TestCase;

/**
 * Closes SCENARIOS.md §11's remaining gap: Cron\SendAbandonedCartFallbackReminders was unit-
 * tested only, never proven against a real ordo_abandoned_cart_reminder_log row and a real
 * ordo_message_log_event "opened" row. Real DI/DB throughout except the one risky collaborator
 * every SendSms/SendWhatsApp integration test already substitutes (a fake SmsSenderInterface
 * instead of a real Twilio API call, same pattern as CampaignSendSmsActionTest) - everything else
 * (CustomerRepositoryInterface, a real quote/cart, Helper\Config reading real store config) is
 * real.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown()/
 * tearDownAfterClass() delete the customer, product, quote, and log rows this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/SendAbandonedCartFallbackRemindersTest.php
 */
class SendAbandonedCartFallbackRemindersTest extends TestCase
{
    private const string SKU = 'ordo-fallback-test-product';

    private static ObjectManagerInterface $objectManager;

    private ?int $customerId = null;
    private ?int $quoteId = null;

    /** @var int[] */
    private array $messageLogIds = [];

    /** @var int[] */
    private array $reminderLogIds = [];

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
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $reminderLogTable = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_abandoned_cart_reminder_log');
        $messageLogTable = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_message_log');

        foreach ($this->reminderLogIds as $id) {
            $connection->delete($reminderLogTable, ['entity_id = ?' => $id]);
        }
        $this->reminderLogIds = [];

        foreach ($this->messageLogIds as $id) {
            $connection->delete($messageLogTable, ['entity_id = ?' => $id]);
        }
        $this->messageLogIds = [];

        if ($this->quoteId !== null) {
            try {
                self::$objectManager->get(CartRepositoryInterface::class)->deleteById($this->quoteId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->quoteId = null;
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

    public function testFallsBackToSmsForAnUnopenedReminderPastTheDelay(): void
    {
        $this->createCustomerWithSmsPhone();
        $this->createCartForCustomer();
        $messageLogId = $this->insertMessageLog();
        $reminderLogId = $this->insertReminderLog($messageLogId, sentHoursAgo: 2);

        $recordingSender = self::$objectManager->create(FallbackRecordingTwilioSmsSender::class);
        $this->runCron($recordingSender);

        self::assertCount(1, $recordingSender->calls, 'An unopened reminder past the delay must fall back.');
        self::assertSame('+15551234567', $recordingSender->calls[0]['toPhone']);

        self::assertNotNull(
            $this->loadFallbackSentAt($reminderLogId),
            'The reminder log row must be claimed (fallback_sent_at set) once attempted.'
        );
    }

    public function testDoesNotFallBackWhenTheReminderWasAlreadyOpened(): void
    {
        $this->createCustomerWithSmsPhone();
        $this->createCartForCustomer();
        $messageLogId = $this->insertMessageLog();
        $reminderLogId = $this->insertReminderLog($messageLogId, sentHoursAgo: 2);
        $this->insertOpenedEvent($messageLogId);

        $recordingSender = self::$objectManager->create(FallbackRecordingTwilioSmsSender::class);
        $this->runCron($recordingSender);

        self::assertSame([], $recordingSender->calls, 'An already-opened reminder must never fall back.');
        self::assertNull(
            $this->loadFallbackSentAt($reminderLogId),
            'An opened reminder was never "due" in the first place - never claimed.'
        );
    }

    public function testDoesNotFallBackTwiceOnceAlreadyClaimed(): void
    {
        $this->createCustomerWithSmsPhone();
        $this->createCartForCustomer();
        $messageLogId = $this->insertMessageLog();
        $reminderLogId = $this->insertReminderLog($messageLogId, sentHoursAgo: 2);

        $firstSender = self::$objectManager->create(FallbackRecordingTwilioSmsSender::class);
        $this->runCron($firstSender);
        self::assertCount(1, $firstSender->calls);

        $secondSender = self::$objectManager->create(FallbackRecordingTwilioSmsSender::class);
        $this->runCron($secondSender);
        self::assertSame([], $secondSender->calls, 'A reminder already claimed must never be attempted again.');
    }

    public function testDoesNotFallBackBeforeTheConfiguredDelayHasPassed(): void
    {
        $this->createCustomerWithSmsPhone();
        $this->createCartForCustomer();
        $messageLogId = $this->insertMessageLog();
        // Sent 30 minutes ago - well inside the 1-hour delay this test configures.
        $reminderLogId = $this->insertReminderLog($messageLogId, sentMinutesAgo: 30);

        $recordingSender = self::$objectManager->create(FallbackRecordingTwilioSmsSender::class);
        $this->runCron($recordingSender);

        self::assertSame([], $recordingSender->calls, 'A too-recent reminder is not due yet.');
        self::assertNull($this->loadFallbackSentAt($reminderLogId));
    }

    // --- fixture builders ------------------------------------------------------------------

    private static function createSimpleProduct(): void
    {
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $product = self::$objectManager->get(ProductInterfaceFactory::class)->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE);
        $product->setAttributeSetId(4);
        $product->setSku(self::SKU);
        $product->setName('Ordo Abandoned Cart Fallback Test Product');
        $product->setPrice(42.0);
        $product->setWebsiteIds([(int) $storeManager->getWebsite()->getId()]);
        $product->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        $product->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH);
        $product->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

        self::$objectManager->get(ProductRepositoryInterface::class)->save($product);
    }

    private function createCustomerWithSmsPhone(): void
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-fallback-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Fallback');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();

        // See CampaignSendSmsActionTest's own comment: a custom attribute set before the FIRST
        // save is silently dropped - set it on the already-created customer instead.
        $saved->setCustomAttribute(AddCustomerSmsPhoneAttribute::ATTRIBUTE_CODE, '+15551234567');
        $customerRepository->save($saved);
        self::$objectManager->get(CustomerRegistry::class)->remove($this->customerId);
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

    private function insertMessageLog(): int
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_message_log');

        $connection->insert($table, [
            'channel' => 'email',
            'to_address' => 'ordo-fallback-test-' . uniqid('', true) . '@example.test',
            'status' => 'sent',
            'customer_id' => $this->customerId,
        ]);
        $messageLogId = (int) $connection->lastInsertId($table);
        $this->messageLogIds[] = $messageLogId;

        return $messageLogId;
    }

    private function insertReminderLog(int $messageLogId, ?int $sentHoursAgo = null, ?int $sentMinutesAgo = null): int
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_abandoned_cart_reminder_log');

        $secondsAgo = $sentHoursAgo !== null ? $sentHoursAgo * 3600 : ($sentMinutesAgo ?? 0) * 60;
        $connection->insert($table, [
            'quote_id' => $this->quoteId,
            'sent_at' => date('Y-m-d H:i:s', time() - $secondsAgo),
            'message_log_id' => $messageLogId,
        ]);
        $reminderLogId = (int) $connection->lastInsertId($table);
        $this->reminderLogIds[] = $reminderLogId;

        return $reminderLogId;
    }

    private function insertOpenedEvent(int $messageLogId): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_message_log_event');

        $connection->insert($table, [
            'message_log_id' => $messageLogId,
            'event_type' => 'opened',
        ]);
    }

    private function loadFallbackSentAt(int $reminderLogId): ?string
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_abandoned_cart_reminder_log');

        $value = $connection->fetchOne(
            $connection->select()->from($table, 'fallback_sent_at')->where('entity_id = ?', $reminderLogId)
        );

        return $value !== false && $value !== null ? (string) $value : null;
    }

    private function runCron(FallbackRecordingTwilioSmsSender $recordingSender): void
    {
        $cronScopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $cronScopeConfig->setValue(
            'ordo_automation/abandoned_cart/fallback_enabled',
            1,
            ScopeInterface::SCOPE_STORE
        );
        $cronScopeConfig->setValue(
            'ordo_automation/abandoned_cart/fallback_delay_hours',
            1,
            ScopeInterface::SCOPE_STORE
        );
        $cronScopeConfig->setValue(
            'ordo_automation/abandoned_cart/fallback_channel',
            'sms',
            ScopeInterface::SCOPE_STORE
        );
        $cronConfig = self::$objectManager->create(Config::class, ['scopeConfig' => $cronScopeConfig]);

        $smsScopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $smsScopeConfig->setValue('ordo_channels/sms/enabled', 1, ScopeInterface::SCOPE_STORE);
        $smsConfig = self::$objectManager->create(Config::class, ['scopeConfig' => $smsScopeConfig]);

        $sendSms = self::$objectManager->create(SendSms::class, [
            'smsSender' => $recordingSender,
            'config' => $smsConfig,
        ]);

        $cron = self::$objectManager->create(SendAbandonedCartFallbackReminders::class, [
            'config' => $cronConfig,
            'sendSms' => $sendSms,
            'sendWhatsApp' => self::$objectManager->get(SendWhatsApp::class),
        ]);
        $cron->execute();
    }
}

/**
 * Records (toPhone, message) instead of calling the real Twilio API. Named distinctly from
 * CampaignSendSmsActionTest's own RecordingTwilioSmsSender (same role, same shape) since the
 * whole Test/Integration directory loads together in a real run - two classes of the same name
 * in the same namespace would fatal on redeclaration.
 */
class FallbackRecordingTwilioSmsSender implements \Ordo\Automation\Model\Sms\SmsSenderInterface
{
    /** @var array<int, array{toPhone: string, message: string}> */
    public array $calls = [];

    public function send(string $toPhone, string $message): string
    {
        $this->calls[] = ['toPhone' => $toPhone, 'message' => $message];

        return 'SM_recorded_test_sid';
    }
}
