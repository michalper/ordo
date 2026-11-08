<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\DispatchCustomerAnniversaryCampaigns;
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
 * Real DI/DB coverage for ADR 0038's customer_anniversary trigger - proves the full path a unit
 * test with a mocked connection can't: a real campaign with a real add_tag action, a real
 * customer whose dob/created_at month+day is backdated to match today, scanned and dispatched by
 * the real cron class, ending in a real ordo_customer_tag row - plus the per-calendar-year claim
 * actually preventing a second dispatch on an immediate re-run.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/DispatchCustomerAnniversaryCampaignsTest.php
 */
class DispatchCustomerAnniversaryCampaignsTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $customerIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    /**
     * Same MutableScopeConfig-instance-sharing technique DispatchCustomerInactiveCampaignsTest
     * already established - see that test's own docblock for why.
     */
    private function makeEnabledConfig(): Config
    {
        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue(
            'ordo_automation/customer_anniversary_trigger/enabled',
            1,
            ScopeInterface::SCOPE_STORE
        );

        return self::$objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);
    }

    private function makeCron(): DispatchCustomerAnniversaryCampaigns
    {
        return self::$objectManager->create(DispatchCustomerAnniversaryCampaigns::class, [
            'config' => $this->makeEnabledConfig(),
        ]);
    }

    protected function tearDown(): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->delete(
            self::$objectManager->get(ResourceConnection::class)
                ->getTableName('ordo_customer_anniversary_trigger_claim'),
            ['customer_id IN (?)' => $this->customerIds ?: [0]]
        );

        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        foreach ($this->campaignIds as $campaignId) {
            /** @var Campaign $campaign */
            $campaign = $campaignFactory->create();
            $campaignResource->load($campaign, $campaignId);
            if ($campaign->getId()) {
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

    private function createCampaignWithAnniversaryTrigger(string $dateSource): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Customer anniversary trigger test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData([
            'campaign_id' => $campaignId,
            'trigger_event' => 'customer_anniversary',
            'params' => json_encode(['date_source' => $dateSource]),
        ]);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'add_tag',
            'params' => json_encode(['tags' => ['anniversary_greeted']]),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);

        return $campaignId;
    }

    /**
     * $monthDayOffsetYears backdates the YEAR only, keeping today's real month+day - so the row
     * genuinely matches "today" regardless of which day this test happens to run on.
     */
    private function createCustomerWithDobToday(int $yearsAgo = 30): int
    {
        $customerId = $this->createPlainCustomer();

        $dob = date('Y-m-d', (int) strtotime("-{$yearsAgo} years"));
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customer = $customerRepository->getById($customerId);
        $customer->setDob($dob);
        $customerRepository->save($customer);

        return $customerId;
    }

    private function createCustomerRegisteredOnThisDayInAPastYear(int $yearsAgo = 2): int
    {
        $customerId = $this->createPlainCustomer();

        // customer_entity.created_at has no setter path through the repository that accepts an
        // arbitrary past date - same direct-UPDATE technique DispatchCustomerInactiveCampaignsTest
        // already uses.
        $createdAt = date('Y-m-d H:i:s', (int) strtotime("-{$yearsAgo} years"));
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->update(
            self::$objectManager->get(ResourceConnection::class)->getTableName('customer_entity'),
            ['created_at' => $createdAt],
            ['entity_id = ?' => $customerId]
        );

        return $customerId;
    }

    private function createPlainCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-anniversary-trigger-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Anniversary');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function flushCampaignCache(): void
    {
        self::$objectManager->get(CacheInterface::class)->clean([CampaignDispatcher::CACHE_TAG]);
    }

    public function testDispatchesAndAppliesTheConfiguredActionForACustomerWhoseBirthdayIsToday(): void
    {
        $campaignId = $this->createCampaignWithAnniversaryTrigger('dob');
        $this->flushCampaignCache();
        $customerId = $this->createCustomerWithDobToday();

        $this->makeCron()->execute();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertContains(
            $customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'anniversary_greeted'),
            'The real add_tag action must have run for this customer whose dob matches today.'
        );

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $claimTable = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_customer_anniversary_trigger_claim');
        $claimCount = (int) $connection->fetchOne(
            $connection->select()->from($claimTable, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('campaign_id = ?', $campaignId)
        );
        self::assertSame(1, $claimCount, 'Exactly one claim row must exist after the first dispatch.');
    }

    public function testDispatchesForACustomerWhoseRegistrationAnniversaryIsToday(): void
    {
        $this->createCampaignWithAnniversaryTrigger('registration');
        $this->flushCampaignCache();
        $customerId = $this->createCustomerRegisteredOnThisDayInAPastYear();

        $this->makeCron()->execute();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertContains(
            $customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'anniversary_greeted')
        );
    }

    public function testDoesNotDispatchForACustomerWithNoDateOfBirthOnFile(): void
    {
        $this->createCampaignWithAnniversaryTrigger('dob');
        $this->flushCampaignCache();
        $customerId = $this->createPlainCustomer();

        $this->makeCron()->execute();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertSame([], $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'anniversary_greeted'));
    }

    public function testASecondRunTheSameCalendarYearDoesNotDispatchAgain(): void
    {
        $campaignId = $this->createCampaignWithAnniversaryTrigger('dob');
        $this->flushCampaignCache();
        $customerId = $this->createCustomerWithDobToday();

        $cron = $this->makeCron();
        $cron->execute();
        $cron->execute();

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $claimTable = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_customer_anniversary_trigger_claim');
        $claimCount = (int) $connection->fetchOne(
            $connection->select()->from($claimTable, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('campaign_id = ?', $campaignId)
        );
        self::assertSame(1, $claimCount, 'A second run the same year must not add another claim row.');
    }
}
