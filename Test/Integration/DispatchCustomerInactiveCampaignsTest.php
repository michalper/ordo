<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\DispatchCustomerInactiveCampaigns;
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
 * Real DI/DB coverage for ADR 0030's customer_inactive trigger - proves the full path a unit
 * test with a mocked connection can't: a real campaign (trigger params persisted as real JSON,
 * same CampaignSaveProcessor::TRIGGER_FIELDS_BY_TYPE allow-list path a real admin save goes
 * through would use) with a real add_tag action, a real customer whose registration is backdated
 * past the trigger's own `days` threshold, scanned and dispatched by the real cron class, ending
 * in a real ordo_customer_tag row - plus the cooldown claim actually preventing a second
 * dispatch on an immediate re-run.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/DispatchCustomerInactiveCampaignsTest.php
 */
class DispatchCustomerInactiveCampaignsTest extends TestCase
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
        self::$objectManager->get(\Magento\Framework\App\MutableScopeConfig::class)->setValue(
            'ordo_automation/customer_inactive_trigger/enabled',
            1,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        self::$objectManager->get(\Magento\Framework\App\MutableScopeConfig::class)->setValue(
            'ordo_automation/customer_inactive_trigger/refire_cooldown_days',
            30,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    protected function tearDown(): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->delete(
            self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_customer_inactive_trigger_claim'),
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

    private function createCampaignWithInactiveTrigger(int $days): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Customer inactive trigger test ' . uniqid('', true));
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
            'trigger_event' => 'customer_inactive',
            'params' => json_encode(['days' => $days]),
        ]);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'add_tag',
            'params' => json_encode(['tags' => ['win_back_candidate']]),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);

        return $campaignId;
    }

    private function createCustomerRegisteredDaysAgo(int $daysAgo): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-inactive-trigger-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Inactive');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        // Same direct-UPDATE backdating technique Test\Mftf\Helper\OrderBackdateHelper already
        // uses for sales_order - customer_entity.created_at has no setter path through the
        // repository that accepts an arbitrary past date.
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $connection->update(
            self::$objectManager->get(ResourceConnection::class)->getTableName('customer_entity'),
            ['created_at' => date('Y-m-d H:i:s', (int) strtotime("-{$daysAgo} days"))],
            ['entity_id = ?' => $customerId]
        );

        return $customerId;
    }

    private function flushCampaignCache(): void
    {
        self::$objectManager->get(CacheInterface::class)->clean([CampaignDispatcher::CACHE_TAG]);
    }

    public function testDispatchesAndAppliesTheConfiguredActionForAnInactiveCustomer(): void
    {
        $campaignId = $this->createCampaignWithInactiveTrigger(days: 30);
        $this->flushCampaignCache();
        $customerId = $this->createCustomerRegisteredDaysAgo(daysAgo: 45);

        self::$objectManager->get(DispatchCustomerInactiveCampaigns::class)->execute();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertContains(
            $customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'win_back_candidate'),
            'The real add_tag action must have run for this genuinely-inactive customer.'
        );

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $claimTable = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_customer_inactive_trigger_claim');
        $claimCount = (int) $connection->fetchOne(
            $connection->select()->from($claimTable, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('campaign_id = ?', $campaignId)
        );
        self::assertSame(1, $claimCount, 'Exactly one claim row must exist after the first dispatch.');
    }

    public function testDoesNotDispatchForACustomerStillWithinTheirOwnInactivityWindow(): void
    {
        $this->createCampaignWithInactiveTrigger(days: 30);
        $this->flushCampaignCache();
        $customerId = $this->createCustomerRegisteredDaysAgo(daysAgo: 5);

        self::$objectManager->get(DispatchCustomerInactiveCampaigns::class)->execute();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertSame([], $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'win_back_candidate'));
    }

    public function testASecondRunWithinTheRefireCooldownDoesNotDispatchAgain(): void
    {
        $campaignId = $this->createCampaignWithInactiveTrigger(days: 30);
        $this->flushCampaignCache();
        $customerId = $this->createCustomerRegisteredDaysAgo(daysAgo: 45);

        $cron = self::$objectManager->get(DispatchCustomerInactiveCampaigns::class);
        $cron->execute();
        $cron->execute();

        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $claimTable = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_customer_inactive_trigger_claim');
        $claimCount = (int) $connection->fetchOne(
            $connection->select()->from($claimTable, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('campaign_id = ?', $campaignId)
        );
        self::assertSame(1, $claimCount, 'A second run inside the cooldown must not add another claim row.');
    }
}
