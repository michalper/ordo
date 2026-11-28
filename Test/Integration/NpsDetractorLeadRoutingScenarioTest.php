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
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for ADR 0093: a detractor NPS response (a real ordo_survey_prompt row)
 * evaluated by the real nps_score_at_most condition, gating a real assign_lead_routing_rule
 * action, run through the real CampaignDispatcher - proving the two new connectors actually wire
 * together into "a customer just gave us a low NPS score - get a rep on this", not just that each
 * piece works in isolation (NpsScoreAtMostTest/AssignLeadRoutingRuleTest are both mock-based unit
 * tests; LeadRoutingRuleAssignmentTest already proves the round-robin state table for real).
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/NpsDetractorLeadRoutingScenarioTest.php
 */
class NpsDetractorLeadRoutingScenarioTest extends TestCase
{
    private const string TRIGGER_EVENT = 'customer_registered';

    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $customerIds = [];

    private ?int $campaignId = null;
    private ?int $ruleId = null;

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
        $resourceConnection->getConnection()->delete(
            $resourceConnection->getTableName('ordo_survey_prompt'),
            ['customer_id IN (?)' => $this->customerIds ?: [0]]
        );

        if ($this->campaignId !== null) {
            $campaignResource = self::$objectManager->get(CampaignResource::class);
            /** @var Campaign $campaign */
            $campaign = self::$objectManager->get(CampaignFactory::class)->create();
            $campaignResource->load($campaign, $this->campaignId);
            if ($campaign->getId()) {
                $campaignResource->delete($campaign);
            }
            $this->campaignId = null;
        }

        if ($this->ruleId !== null) {
            $leadRoutingRuleResource = self::$objectManager->get(LeadRoutingRuleResource::class);
            $rule = self::$objectManager->get(LeadRoutingRuleFactory::class)->create();
            $leadRoutingRuleResource->load($rule, $this->ruleId);
            if ($rule->getEntityId()) {
                $leadRoutingRuleResource->delete($rule);
            }
            $this->ruleId = null;
        }

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

    private function createCampaignWithNpsDetractorEscalation(): void
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('NPS detractor escalation test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $this->campaignId = (int) $campaign->getEntityId();

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData(['campaign_id' => $this->campaignId, 'trigger_event' => self::TRIGGER_EVENT]);
        $triggerResource->save($trigger);

        $conditionFactory = self::$objectManager->get(CampaignConditionFactory::class);
        $conditionResource = self::$objectManager->get(CampaignConditionResource::class);
        $condition = $conditionFactory->create();
        $condition->setData([
            'campaign_id' => $this->campaignId,
            'type' => 'nps_score_at_most',
            'params' => json_encode(['threshold' => 6]),
            'sort_order' => 0,
        ]);
        $conditionResource->save($condition);

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $this->campaignId,
            'type' => 'assign_lead_routing_rule',
            'params' => json_encode([]),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $actionResource->save($action);
    }

    private function createCatchAllLeadRoutingRule(): void
    {
        $leadRoutingRuleFactory = self::$objectManager->get(LeadRoutingRuleFactory::class);
        $leadRoutingRuleResource = self::$objectManager->get(LeadRoutingRuleResource::class);

        $rule = $leadRoutingRuleFactory->create();
        $rule->setName('NPS detractor escalation test rule ' . uniqid('', true));
        $rule->setAttributeCode('');
        $rule->setOperator('');
        $rule->setValue('');
        $rule->setReps(json_encode([['email' => 'detractor-rep@example.test', 'name' => 'Detractor Rep']]));
        $rule->setEnabled(true);
        $rule->setSortOrder(0);
        $leadRoutingRuleResource->save($rule);
        $this->ruleId = (int) $rule->getEntityId();
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-nps-detractor-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Detractor');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function recordNpsResponse(int $customerId, int $score): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $resourceConnection->getConnection()->insert($resourceConnection->getTableName('ordo_survey_prompt'), [
            'customer_id' => $customerId,
            'question' => 'How likely are you to recommend us to a friend?',
            'score' => $score,
            'responded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function flushCampaignCache(): void
    {
        self::$objectManager->get(CacheInterface::class)->clean([CampaignDispatcher::CACHE_TAG]);
    }

    public function testEscalatesADetractorResponseToLeadRouting(): void
    {
        $this->createCampaignWithNpsDetractorEscalation();
        $this->createCatchAllLeadRoutingRule();
        $this->flushCampaignCache();

        $customerId = $this->createCustomer();
        $this->recordNpsResponse($customerId, 2);

        self::$objectManager->get(CampaignDispatcher::class)->dispatch(self::TRIGGER_EVENT, [
            'customer_id' => $customerId,
        ]);

        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $repEmail = $customerRepository->getById($customerId)
            ->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)?->getValue();

        self::assertSame(
            'detractor-rep@example.test',
            $repEmail,
            'A detractor (score <= 6) must be escalated to the matching lead routing rule.'
        );
    }

    public function testDoesNotEscalateAPromoterResponse(): void
    {
        $this->createCampaignWithNpsDetractorEscalation();
        $this->createCatchAllLeadRoutingRule();
        $this->flushCampaignCache();

        $customerId = $this->createCustomer();
        $this->recordNpsResponse($customerId, 9);

        self::$objectManager->get(CampaignDispatcher::class)->dispatch(self::TRIGGER_EVENT, [
            'customer_id' => $customerId,
        ]);

        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $repEmail = $customerRepository->getById($customerId)
            ->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)?->getValue();

        self::assertNull($repEmail, 'A promoter (score above the threshold) must never be escalated.');
    }

    public function testDoesNotEscalateACustomerWhoNeverAnswered(): void
    {
        $this->createCampaignWithNpsDetractorEscalation();
        $this->createCatchAllLeadRoutingRule();
        $this->flushCampaignCache();

        $customerId = $this->createCustomer();

        self::$objectManager->get(CampaignDispatcher::class)->dispatch(self::TRIGGER_EVENT, [
            'customer_id' => $customerId,
        ]);

        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $repEmail = $customerRepository->getById($customerId)
            ->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)?->getValue();

        self::assertNull($repEmail);
    }
}
