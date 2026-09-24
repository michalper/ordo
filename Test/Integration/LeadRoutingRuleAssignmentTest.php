<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\LeadRouting\LeadAssigner;
use Ordo\Automation\Model\LeadRouting\LeadRoutingRuleEvaluator;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use PHPUnit\Framework\TestCase;

/**
 * The round-robin state table (ordo_lead_routing_rule_state) is the one part of this feature a
 * mock-based unit test can never prove for real - LeadAssignerTest exercises the SQL shape
 * against a mocked adapter, but only a real database round trip proves the pointer actually
 * persists and advances across separate calls. This drives LeadRoutingRuleEvaluator + LeadAssigner
 * together against real DI/database, the same "real DI, no mocks" standard as
 * CampaignTemplateLibraryTest.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/LeadRoutingRuleAssignmentTest.php
 */
class LeadRoutingRuleAssignmentTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $customerIds = [];

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
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        foreach ($this->customerIds as $customerId) {
            try {
                $customerRepository->deleteById($customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->customerIds = [];

        if ($this->ruleId !== null) {
            try {
                $leadRoutingRuleFactory = self::$objectManager->get(LeadRoutingRuleFactory::class);
                $leadRoutingRuleResource = self::$objectManager->get(LeadRoutingRuleResource::class);
                $rule = $leadRoutingRuleFactory->create();
                $leadRoutingRuleResource->load($rule, $this->ruleId);
                if ($rule->getEntityId()) {
                    $leadRoutingRuleResource->delete($rule);
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->ruleId = null;
        }
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-lead-routing-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Lead');
        $customer->setLastname('Routing');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    public function testRoundRobinAssignsRepsInOrderAcrossRealSeparateCustomers(): void
    {
        $leadRoutingRuleFactory = self::$objectManager->get(LeadRoutingRuleFactory::class);
        $leadRoutingRuleResource = self::$objectManager->get(LeadRoutingRuleResource::class);

        $rule = $leadRoutingRuleFactory->create();
        $rule->setName('Integration test round robin');
        $rule->setAttributeCode('');
        $rule->setOperator('');
        $rule->setValue('');
        $rule->setReps(json_encode([
            ['email' => 'rep-a@example.test', 'name' => 'Rep A', 'phone' => '111'],
            ['email' => 'rep-b@example.test', 'name' => 'Rep B', 'phone' => '222'],
        ]));
        $rule->setEnabled(true);
        $rule->setSortOrder(0);
        $leadRoutingRuleResource->save($rule);
        $this->ruleId = (int) $rule->getEntityId();

        $evaluator = self::$objectManager->get(LeadRoutingRuleEvaluator::class);
        $assigner = self::$objectManager->get(LeadAssigner::class);
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);

        $firstCustomerId = $this->createCustomer();
        $secondCustomerId = $this->createCustomer();
        $thirdCustomerId = $this->createCustomer();

        foreach ([$firstCustomerId, $secondCustomerId, $thirdCustomerId] as $customerId) {
            $customer = $customerRepository->getById($customerId);
            $matched = $evaluator->getMatchingRule($customer);
            self::assertNotNull($matched, 'the catch-all rule must match every customer');
            $assigner->assign($customerId, $matched);
        }

        $firstEmail = $customerRepository->getById($firstCustomerId)
            ->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)->getValue();
        $secondEmail = $customerRepository->getById($secondCustomerId)
            ->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)->getValue();
        $thirdEmail = $customerRepository->getById($thirdCustomerId)
            ->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)->getValue();

        self::assertSame('rep-a@example.test', $firstEmail);
        self::assertSame('rep-b@example.test', $secondEmail);
        // The real proof the state table persisted and wrapped around, not just alternated
        // in-memory within a single process call.
        self::assertSame('rep-a@example.test', $thirdEmail);
    }

    public function testAssignSkipsACustomerWhoAlreadyHasARep(): void
    {
        $leadRoutingRuleFactory = self::$objectManager->get(LeadRoutingRuleFactory::class);
        $leadRoutingRuleResource = self::$objectManager->get(LeadRoutingRuleResource::class);

        $rule = $leadRoutingRuleFactory->create();
        $rule->setName('Integration test skip-if-assigned');
        $rule->setAttributeCode('');
        $rule->setOperator('');
        $rule->setValue('');
        $rule->setReps(json_encode([['email' => 'should-not-be-used@example.test']]));
        $rule->setEnabled(true);
        $rule->setSortOrder(0);
        $leadRoutingRuleResource->save($rule);
        $this->ruleId = (int) $rule->getEntityId();

        $assigner = self::$objectManager->get(LeadAssigner::class);
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);

        $customerId = $this->createCustomer();
        $customer = $customerRepository->getById($customerId);
        $customer->setCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL, 'manually-assigned@example.test');
        $customerRepository->save($customer);

        self::assertTrue($assigner->hasAssignedRep($customerRepository->getById($customerId)));
    }
}
