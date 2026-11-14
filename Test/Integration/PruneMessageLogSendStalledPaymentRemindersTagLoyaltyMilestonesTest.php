<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\PaymentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\PruneMessageLog;
use Ordo\Automation\Cron\SendStalledPaymentReminders;
use Ordo\Automation\Cron\TagLoyaltyMilestones;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Test\Integration\Fixture\RecordingTransportBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for three independent, lower-priority crons found with zero
 * Integration/MFTF coverage by the 2026-11-14 full-sweep audit (Test/Mftf/SCENARIOS.md §42):
 * Cron\PruneMessageLog, Cron\SendStalledPaymentReminders, Cron\TagLoyaltyMilestones. Each was
 * only ever unit-tested against a mocked connection - bundled into one PR/file since each is a
 * single, simple, real-write-path proof rather than a multi-scenario feature.
 *
 * SendStalledPaymentReminders uses the same "override just the TransportBuilder tail" pattern
 * Test/Integration/CampaignSendEmailActionTest.php already establishes (RecordingTransportBuilder)
 * - every other real dependency (ResourceConnection, ConsentManager, CustomerMapBuilder) comes
 * from real DI, only the actual mail transport is faked.
 *
 * No transactional rollback (see magento-integration-test-lite) - each test's own tearDown-style
 * cleanup deletes what it created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/PruneMessageLogSendStalledPaymentRemindersTagLoyaltyMilestonesTest.php
 */
class PruneMessageLogSendStalledPaymentRemindersTagLoyaltyMilestonesTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $messageLogIds = [];

    /** @var int[] */
    private array $orderIds = [];

    /** @var int[] */
    private array $customerIds = [];

    /** @var array{customerId: int, tag: string}[] */
    private array $tagsToClean = [];

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
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        if ($this->messageLogIds !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_message_log'),
                ['entity_id IN (?)' => $this->messageLogIds]
            );
        }
        $this->messageLogIds = [];

        if ($this->orderIds !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_stalled_payment_reminder_log'),
                ['order_id IN (?)' => $this->orderIds]
            );
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

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        foreach ($this->tagsToClean as $entry) {
            $tagManager->removeTag($entry['customerId'], $entry['tag']);
        }
        $this->tagsToClean = [];

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

    // --- shared fixture builders -------------------------------------------------------------

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-small-crons-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('SmallCrons');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    // --- Cron\PruneMessageLog ----------------------------------------------------------------

    private function insertMessageLogSentDaysAgo(int $daysAgo): int
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_message_log');

        $connection->insert($table, [
            'channel' => 'email',
            'to_address' => 'ordo-prune-test-' . uniqid('', true) . '@example.test',
            'provider_message_id' => 'ordo-prune-test-' . uniqid('', true),
            'status' => 'delivered',
        ]);
        $id = (int) $connection->lastInsertId($table);
        $connection->update(
            $table,
            ['sent_at' => date('Y-m-d H:i:s', (int) strtotime("-{$daysAgo} days"))],
            ['entity_id = ?' => $id]
        );
        $this->messageLogIds[] = $id;

        return $id;
    }

    private function messageLogExists(int $id): bool
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($resourceConnection->getTableName('ordo_message_log'), 'COUNT(*)')
                ->where('entity_id = ?', $id)
        ) > 0;
    }

    public function testPruneMessageLogDeletesOnlyRowsPastTheRetentionWindow(): void
    {
        $oldId = $this->insertMessageLogSentDaysAgo(200);
        $recentId = $this->insertMessageLogSentDaysAgo(5);

        self::$objectManager->create(PruneMessageLog::class)->execute();

        self::assertFalse($this->messageLogExists($oldId), 'A message sent 200 days ago must be pruned (default retention is 180 days).');
        self::assertTrue($this->messageLogExists($recentId), 'A message sent 5 days ago must NOT be pruned.');
        // messageLogIds already drained by the cron for $oldId - avoid a tearDown delete() on a
        // row that no longer exists, which is harmless but pointless; $recentId still needs it.
        $this->messageLogIds = [$recentId];
    }

    // --- Cron\SendStalledPaymentReminders ------------------------------------------------------

    private function makeStalledPaymentConfig(): Config
    {
        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_automation/stalled_payment/enabled', 1, ScopeInterface::SCOPE_STORE);
        $scopeConfig->setValue('ordo_automation/stalled_payment/lead_hours', 24, ScopeInterface::SCOPE_STORE);

        return self::$objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);
    }

    private function createPendingPaymentOrderHoursAgo(int $customerId, int $hoursAgo): int
    {
        /** @var Order $order */
        $order = self::$objectManager->get(OrderFactory::class)->create();
        $order->setCustomerId($customerId);
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus('pending_payment');
        $order->setStoreId(1);
        $order->setGrandTotal(10.0);
        $order->setBaseGrandTotal(10.0);
        $order->setOrderCurrencyCode('USD');
        $order->setBaseCurrencyCode('USD');

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = self::$objectManager->get(PaymentFactory::class)->create();
        $payment->setMethod('checkmo');
        $order->setPayment($payment);

        self::$objectManager->get(OrderResource::class)->save($order);
        $orderId = (int) $order->getId();
        $this->orderIds[] = $orderId;

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->update(
            self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order'),
            ['created_at' => date('Y-m-d H:i:s', (int) strtotime("-{$hoursAgo} hours"))],
            ['entity_id = ?' => $orderId]
        );

        return $orderId;
    }

    private function makeCronWithRecordingTransport(Config $config): array
    {
        $recordingBuilder = self::$objectManager->create(RecordingTransportBuilder::class);
        $emailSender = self::$objectManager->create(ReminderEmailSender::class, [
            'transportBuilder' => $recordingBuilder,
        ]);
        $cron = self::$objectManager->create(SendStalledPaymentReminders::class, [
            'config' => $config,
            'emailSender' => $emailSender,
        ]);

        return [$cron, $recordingBuilder];
    }

    private function reminderLogCountForOrder(int $orderId): int
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($resourceConnection->getTableName('ordo_stalled_payment_reminder_log'), 'COUNT(*)')
                ->where('order_id = ?', $orderId)
        );
    }

    public function testSendStalledPaymentRemindersSendsAndClaimsForARealEligibleOrder(): void
    {
        $customerId = $this->createCustomer();
        $orderId = $this->createPendingPaymentOrderHoursAgo($customerId, hoursAgo: 48);

        [$cron, $recordingBuilder] = $this->makeCronWithRecordingTransport($this->makeStalledPaymentConfig());
        $cron->execute();

        self::assertNotEmpty($recordingBuilder->calls['to'] ?? [], 'A real reminder email must have been built and sent for this eligible order.');
        self::assertSame(1, $this->reminderLogCountForOrder($orderId));
    }

    public function testSendStalledPaymentRemindersDoesNotResendOnASecondRun(): void
    {
        $customerId = $this->createCustomer();
        $orderId = $this->createPendingPaymentOrderHoursAgo($customerId, hoursAgo: 48);

        $config = $this->makeStalledPaymentConfig();
        [$firstCron, $firstBuilder] = $this->makeCronWithRecordingTransport($config);
        $firstCron->execute();
        self::assertNotEmpty($firstBuilder->calls['to'] ?? []);

        [$secondCron, $secondBuilder] = $this->makeCronWithRecordingTransport($config);
        $secondCron->execute();

        self::assertEmpty($secondBuilder->calls['to'] ?? [], 'A second run must not send a second reminder for the same order.');
        self::assertSame(1, $this->reminderLogCountForOrder($orderId), 'A second run must not add a second claim row either.');
    }

    public function testSendStalledPaymentRemindersIgnoresAnOrderStillWithinTheLeadWindow(): void
    {
        $customerId = $this->createCustomer();
        $this->createPendingPaymentOrderHoursAgo($customerId, hoursAgo: 1);

        [$cron, $recordingBuilder] = $this->makeCronWithRecordingTransport($this->makeStalledPaymentConfig());
        $cron->execute();

        self::assertEmpty($recordingBuilder->calls['to'] ?? [], 'An order only 1 hour old must not be reminded yet (lead_hours=24).');
    }

    // --- Cron\TagLoyaltyMilestones ------------------------------------------------------------

    public function testTagLoyaltyMilestonesTagsACustomerWithARealOrderCountMilestone(): void
    {
        $customerId = $this->createCustomer();
        for ($i = 0; $i < 5; $i++) {
            $order = self::$objectManager->get(OrderFactory::class)->create();
            $order->setCustomerId($customerId);
            $order->setState(Order::STATE_COMPLETE);
            $order->setStatus('complete');
            $order->setStoreId(1);
            $order->setGrandTotal(10.0);
            $order->setBaseGrandTotal(10.0);
            $order->setOrderCurrencyCode('USD');
            $order->setBaseCurrencyCode('USD');
            $payment = self::$objectManager->get(PaymentFactory::class)->create();
            $payment->setMethod('checkmo');
            $order->setPayment($payment);
            self::$objectManager->get(OrderResource::class)->save($order);
            $this->orderIds[] = (int) $order->getId();
        }

        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_automation/loyalty_milestones/enabled', 1, ScopeInterface::SCOPE_STORE);
        $config = self::$objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);

        self::$objectManager->create(TagLoyaltyMilestones::class, ['config' => $config])->execute();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => 'milestone_order_5'];
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => 'milestone_order_1'];

        self::assertContains(
            $customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'milestone_order_5'),
            'A customer with 5 real non-canceled orders must get the real milestone_order_5 tag.'
        );
    }
}
