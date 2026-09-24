<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\LeadRouting\LeadAssigner;
use Ordo\Automation\Model\LeadRouting\LeadRoutingRuleEvaluator;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Observer\AssignLeadRoutingRule;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AssignLeadRoutingRuleTest extends TestCase
{
    private Config $config;
    private LeadRoutingRuleEvaluator $leadRoutingRuleEvaluator;
    private LeadAssigner $leadAssigner;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->leadRoutingRuleEvaluator = $this->createStub(LeadRoutingRuleEvaluator::class);
        $this->leadAssigner = $this->createMock(LeadAssigner::class);
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function makeObserver(array $eventData): EventObserver
    {
        $event = new Event($eventData);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    private function makeCustomer(int $id): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $this->customerRepository->method('getById')->willReturn($customer);

        return $customer;
    }

    private function makeObserverInstance(): AssignLeadRoutingRule
    {
        return new AssignLeadRoutingRule(
            $this->config,
            $this->leadRoutingRuleEvaluator,
            $this->leadAssigner,
            $this->customerRepository,
            $this->logger
        );
    }

    public function testExecuteDoesNothingWhenLeadRoutingDisabled(): void
    {
        $this->config->method('isLeadRoutingEnabled')->willReturn(false);

        $this->leadAssigner->expects(self::never())->method('assign');

        $this->makeObserverInstance()->execute($this->makeObserver(['customer_id' => 42]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenCustomerIdMissing(): void
    {
        $this->config->method('isLeadRoutingEnabled')->willReturn(true);

        $this->leadAssigner->expects(self::never())->method('assign');

        $this->makeObserverInstance()->execute($this->makeObserver([]));
    }

    public function testExecuteResolvesCustomerIdFromDataObjectEventPayload(): void
    {
        $this->config->method('isLeadRoutingEnabled')->willReturn(true);
        $customer = $this->makeCustomer(42);

        $legacyCustomer = new DataObject(['id' => 42]);

        $this->leadAssigner->expects(self::once())->method('hasAssignedRep')->with($customer)->willReturn(true);
        $this->leadAssigner->expects(self::never())->method('assign');

        $this->makeObserverInstance()->execute($this->makeObserver(['customer' => $legacyCustomer]));
    }

    public function testExecuteSkipsAssignmentWhenCustomerAlreadyHasRep(): void
    {
        $this->config->method('isLeadRoutingEnabled')->willReturn(true);
        $customer = $this->makeCustomer(42);

        $this->leadAssigner->expects(self::once())->method('hasAssignedRep')->with($customer)->willReturn(true);
        $this->leadAssigner->expects(self::never())->method('assign');

        $this->makeObserverInstance()->execute($this->makeObserver(['customer_id' => 42]));
    }

    public function testExecuteSkipsAssignmentWhenNoRuleMatches(): void
    {
        $this->config->method('isLeadRoutingEnabled')->willReturn(true);
        $customer = $this->makeCustomer(42);

        $this->leadAssigner->expects(self::once())->method('hasAssignedRep')->with($customer)->willReturn(false);
        $this->leadRoutingRuleEvaluator->method('getMatchingRule')->willReturn(null);
        $this->leadAssigner->expects(self::never())->method('assign');

        $this->makeObserverInstance()->execute($this->makeObserver(['customer_id' => 42]));
    }

    public function testExecuteAssignsCustomerToMatchedRule(): void
    {
        $this->config->method('isLeadRoutingEnabled')->willReturn(true);
        $customer = $this->makeCustomer(42);

        $rule = $this->createStub(LeadRoutingRule::class);
        $this->leadAssigner->expects(self::once())->method('hasAssignedRep')->with($customer)->willReturn(false);
        $this->leadRoutingRuleEvaluator->method('getMatchingRule')->willReturn($rule);
        $this->leadAssigner->expects(self::once())->method('assign')->with(42, $rule);

        $this->makeObserverInstance()->execute($this->makeObserver(['customer_id' => 42]));
    }

    /**
     * Same defensive shape as EvaluateCustomerScoreRules: a bug here must never break
     * registration or the score-threshold campaign dispatch that fired this observer.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCatchesAndLogsInsteadOfLettingCallerBreak(): void
    {
        $this->config->method('isLeadRoutingEnabled')->willReturn(true);
        $this->makeCustomer(42);
        $this->leadAssigner->method('hasAssignedRep')
            ->willThrowException(new \Error('Call to undefined method on customer'));
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->logger->expects(self::once())->method('error')->with(
            self::stringContains('lead-routing assignment failed for customer #42')
        );

        $this->makeObserverInstance()->execute($this->makeObserver(['customer_id' => 42]));
    }
}
