<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapManager;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use Ordo\Automation\Setup\Patch\Data\AddCustomerMaxMessagesPerWindowAttribute;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for ADR 0094: a customer's own `ordo_max_messages_per_window` EAV
 * attribute (the same one Model\ConsentPreferencesManagement::setMyMaxMessagesPerWindow() writes
 * on the storefront "Communication Preferences" page) actually tightens
 * FrequencyCapManager::hasCapacity() below the admin-configured cap, and never loosens it -
 * proving the real customer-attribute round trip a unit test's mocked CustomerRepositoryInterface
 * can't.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/FrequencyCapCustomerPreferenceScenarioTest.php
 */
class FrequencyCapCustomerPreferenceScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $customerIds = [];

    /** @var int[] */
    private array $messageLogIds = [];

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
        foreach ($this->messageLogIds as $id) {
            $connection->delete($resourceConnection->getTableName('ordo_message_log'), ['entity_id = ?' => $id]);
        }
        $this->messageLogIds = [];

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

    private function createCustomer(?int $maxMessagesPerWindow = null): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-frequency-pref-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Frequency');
        $customer->setLastname('Preference');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        if ($maxMessagesPerWindow !== null) {
            $saved->setCustomAttribute(
                AddCustomerMaxMessagesPerWindowAttribute::ATTRIBUTE_CODE,
                $maxMessagesPerWindow
            );
            $customerRepository->save($saved);
        }

        return $customerId;
    }

    private function recordSentMessages(int $customerId, int $count): void
    {
        $writer = self::$objectManager->get(MessageLogWriter::class);
        for ($i = 0; $i < $count; $i++) {
            $id = $writer->recordSent('email', $customerId, 'frequency-pref-test@example.test', 'msg-' . uniqid('', true));
            if ($id !== null) {
                $this->messageLogIds[] = $id;
            }
        }
    }

    private function makeEnabledConfig(int $adminMaxMessages): Config
    {
        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_channels/frequency_cap/enabled', 1, ScopeInterface::SCOPE_STORE);
        $scopeConfig->setValue('ordo_channels/frequency_cap/max_messages', $adminMaxMessages, ScopeInterface::SCOPE_STORE);
        $scopeConfig->setValue('ordo_channels/frequency_cap/window_hours', 24, ScopeInterface::SCOPE_STORE);

        return self::$objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);
    }

    private function makeManager(int $adminMaxMessages): FrequencyCapManager
    {
        return self::$objectManager->create(FrequencyCapManager::class, [
            'config' => $this->makeEnabledConfig($adminMaxMessages),
        ]);
    }

    public function testACustomersOwnTighterPreferenceReducesCapacityBelowTheAdminCap(): void
    {
        $customerId = $this->createCustomer(maxMessagesPerWindow: 2);
        $this->recordSentMessages($customerId, 2);

        // Admin cap alone (5) would still allow this customer more messages - their own tighter
        // preference (2) must be the one that actually exhausts capacity here.
        self::assertFalse($this->makeManager(adminMaxMessages: 5)->hasCapacity($customerId));
    }

    public function testACustomersOwnLooserPreferenceNeverExpandsBeyondTheAdminCap(): void
    {
        $customerId = $this->createCustomer(maxMessagesPerWindow: 10);
        $this->recordSentMessages($customerId, 5);

        // The customer asked for a looser cap (10) than the admin allows (5) - the admin cap
        // must still win.
        self::assertFalse($this->makeManager(adminMaxMessages: 5)->hasCapacity($customerId));
    }

    public function testACustomerWithNoPreferenceUsesTheAdminCapAlone(): void
    {
        $customerId = $this->createCustomer();
        $this->recordSentMessages($customerId, 4);

        self::assertTrue($this->makeManager(adminMaxMessages: 5)->hasCapacity($customerId));
    }
}
