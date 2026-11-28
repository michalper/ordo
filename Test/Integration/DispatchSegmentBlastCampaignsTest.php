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
use Ordo\Automation\Cron\DispatchSegmentBlastCampaigns;
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
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for ADR 0075's segment_blast trigger - proves the full path a unit test
 * with a mocked connection can't: a real segment (a tag-based condition, the same shape
 * SegmentBulkActionQueueWiringTest already proves resolves via real SQL) with a real member, a
 * real campaign with a real add_tag action pointed at that segment, scanned and dispatched by the
 * real cron class, ending in a real ordo_customer_tag row - plus the one-dispatch-per-pair-forever
 * claim actually preventing a second dispatch on an immediate re-run.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/DispatchSegmentBlastCampaignsTest.php
 */
class DispatchSegmentBlastCampaignsTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $segmentIds = [];

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
     * Same real MutableScopeConfig-instance-sharing requirement CampaignSendSmsActionTest/
     * DispatchCustomerInactiveCampaignsTest already document - Helper\Config's own injected
     * ScopeConfigInterface never sees a MutableScopeConfig::setValue() call made against a
     * separately-resolved instance.
     */
    private function makeEnabledConfig(): Config
    {
        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_automation/segment_blast_trigger/enabled', 1, ScopeInterface::SCOPE_STORE);

        return self::$objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);
    }

    private function makeCron(): DispatchSegmentBlastCampaigns
    {
        return self::$objectManager->create(DispatchSegmentBlastCampaigns::class, [
            'config' => $this->makeEnabledConfig(),
        ]);
    }

    protected function tearDown(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $resourceConnection->getConnection()->delete(
            $resourceConnection->getTableName('ordo_segment_blast_trigger_claim'),
            ['customer_id IN (?)' => $this->customerIds ?: [0]]
        );

        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);
        foreach ($this->segmentIds as $segmentId) {
            /** @var \Ordo\Automation\Model\Segment $segment */
            $segment = $segmentFactory->create();
            $segmentResource->load($segment, $segmentId);
            if ($segment->getEntityId()) {
                $segmentResource->delete($segment);
            }
        }
        $this->segmentIds = [];

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

    private function createTagSegment(string $tag): int
    {
        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);

        /** @var \Ordo\Automation\Model\Segment $segment */
        $segment = $segmentFactory->create();
        $segment->setData(['name' => 'Segment blast test ' . uniqid('', true), 'enabled' => true]);
        $segmentResource->save($segment);
        $segmentId = (int) $segment->getEntityId();
        $this->segmentIds[] = $segmentId;

        $conditionFactory = self::$objectManager->get(SegmentConditionFactory::class);
        $conditionResource = self::$objectManager->get(SegmentConditionResource::class);
        $condition = $conditionFactory->create();
        $condition->setSegmentId($segmentId);
        $condition->setType('tag');
        $condition->setParamsJson(json_encode(['tags' => [$tag]]));
        $condition->setSortOrder(0);
        $conditionResource->save($condition);

        return $segmentId;
    }

    private function createCampaignWithSegmentBlastTrigger(int $segmentId, ?string $scheduledAt = null): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Segment blast trigger test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $params = ['segment_id' => $segmentId];
        if ($scheduledAt !== null) {
            $params['scheduled_at'] = $scheduledAt;
        }

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData([
            'campaign_id' => $campaignId,
            'trigger_event' => 'segment_blast',
            'params' => json_encode($params),
        ]);
        $triggerResource->save($trigger);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'add_tag',
            'params' => json_encode(['tags' => ['segment_blast_received']]),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);

        return $campaignId;
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-segment-blast-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Segment');
        $customer->setLastname('Blast');
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

    public function testDispatchesAndAppliesTheConfiguredActionForEverySegmentMember(): void
    {
        $tag = 'segment-blast-member-' . uniqid('', true);
        $customerId = $this->createCustomer();
        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        $tagManager->addTag($customerId, $tag);

        $segmentId = $this->createTagSegment($tag);
        $campaignId = $this->createCampaignWithSegmentBlastTrigger($segmentId);
        $this->flushCampaignCache();

        $this->makeCron()->execute();

        self::assertContains(
            $customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'segment_blast_received'),
            'The real add_tag action must have run for this genuine segment member.'
        );

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $claimCount = (int) $resourceConnection->getConnection()->fetchOne(
            $resourceConnection->getConnection()->select()
                ->from($resourceConnection->getTableName('ordo_segment_blast_trigger_claim'), 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('campaign_id = ?', $campaignId)
        );
        self::assertSame(1, $claimCount, 'Exactly one claim row must exist after the first dispatch.');
    }

    public function testDoesNotDispatchBeforeAFutureScheduledAt(): void
    {
        $tag = 'segment-blast-future-' . uniqid('', true);
        $customerId = $this->createCustomer();
        self::$objectManager->get(CustomerTagManager::class)->addTag($customerId, $tag);

        $segmentId = $this->createTagSegment($tag);
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->createCampaignWithSegmentBlastTrigger($segmentId, scheduledAt: $future);
        $this->flushCampaignCache();

        $this->makeCron()->execute();

        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        self::assertSame([], $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'segment_blast_received'));
    }

    public function testASecondRunDoesNotDispatchAgainForAnAlreadyClaimedCustomer(): void
    {
        $tag = 'segment-blast-second-run-' . uniqid('', true);
        $customerId = $this->createCustomer();
        self::$objectManager->get(CustomerTagManager::class)->addTag($customerId, $tag);

        $segmentId = $this->createTagSegment($tag);
        $campaignId = $this->createCampaignWithSegmentBlastTrigger($segmentId);
        $this->flushCampaignCache();

        $cron = $this->makeCron();
        $cron->execute();
        $cron->execute();

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $claimCount = (int) $resourceConnection->getConnection()->fetchOne(
            $resourceConnection->getConnection()->select()
                ->from($resourceConnection->getTableName('ordo_segment_blast_trigger_claim'), 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('campaign_id = ?', $campaignId)
        );
        self::assertSame(1, $claimCount, 'A second run must never add a second claim row for the same pair.');
    }

    /**
     * Regression test for a real bug: buildClaimRow()'s 'match' side used to key the claim
     * conditions by a bare column name instead of a Zend_Db_Select::where() condition string
     * ('customer_id = ?'), which silently degrades ReminderLogStore::countMatching() to a
     * truthy-column check true for ANY existing row - so once this table held even one unrelated
     * claim, every OTHER (customer, campaign) pair's claim() call falsely reported "already
     * claimed" and was never dispatched. See DispatchCustomerAnniversaryCampaignsTest's own
     * identical regression test for the full mechanism.
     */
    public function testAPreExistingUnrelatedClaimRowDoesNotBlockADifferentCustomersDispatch(): void
    {
        $unrelatedTag = 'segment-blast-unrelated-' . uniqid('', true);
        $unrelatedCustomerId = $this->createCustomer();
        self::$objectManager->get(CustomerTagManager::class)->addTag($unrelatedCustomerId, $unrelatedTag);
        $unrelatedSegmentId = $this->createTagSegment($unrelatedTag);
        $unrelatedCampaignId = $this->createCampaignWithSegmentBlastTrigger($unrelatedSegmentId);
        $this->flushCampaignCache();
        $this->makeCron()->execute();

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $unrelatedClaimCount = (int) $resourceConnection->getConnection()->fetchOne(
            $resourceConnection->getConnection()->select()
                ->from($resourceConnection->getTableName('ordo_segment_blast_trigger_claim'), 'COUNT(*)')
                ->where('customer_id = ?', $unrelatedCustomerId)
                ->where('campaign_id = ?', $unrelatedCampaignId)
        );
        self::assertSame(1, $unrelatedClaimCount, 'Sanity check: the unrelated claim row must exist.');

        $tag = 'segment-blast-after-unrelated-' . uniqid('', true);
        $customerId = $this->createCustomer();
        $tagManager = self::$objectManager->get(CustomerTagManager::class);
        $tagManager->addTag($customerId, $tag);
        $segmentId = $this->createTagSegment($tag);
        $this->createCampaignWithSegmentBlastTrigger($segmentId);
        $this->flushCampaignCache();

        $this->makeCron()->execute();

        self::assertContains(
            $customerId,
            $tagManager->getCustomerIdsWithTagFromSet([$customerId], 'segment_blast_received'),
            'A pre-existing, unrelated claim row must not block this different customer\'s dispatch.'
        );
    }
}
