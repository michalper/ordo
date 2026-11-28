<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\Campaign\Action\AssignLeadRoutingRule;
use Ordo\Automation\Model\LeadRouting\LeadAssigner;
use Ordo\Automation\Model\LeadRouting\LeadRoutingRuleEvaluator;
use Ordo\Automation\Model\LeadRoutingRule;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AssignLeadRoutingRuleTest extends TestCase
{
    private CustomerRepositoryInterface $customerRepository;
    private LeadRoutingRuleEvaluator $leadRoutingRuleEvaluator;
    private LeadAssigner $leadAssigner;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->leadRoutingRuleEvaluator = $this->createStub(LeadRoutingRuleEvaluator::class);
        $this->leadAssigner = $this->createMock(LeadAssigner::class);
    }

    private function makeAction(): AssignLeadRoutingRule
    {
        return new AssignLeadRoutingRule(
            $this->customerRepository,
            $this->leadRoutingRuleEvaluator,
            $this->leadAssigner
        );
    }

    private function makeCustomer(int $id): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $this->customerRepository->method('getById')->willReturn($customer);

        return $customer;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenCustomerIdIsMissing(): void
    {
        $this->leadAssigner->expects(self::never())->method('assign');

        $context = [];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenCustomerCannotBeLoaded(): void
    {
        $this->customerRepository->method('getById')->willThrowException(
            new NoSuchEntityException(__('No such entity.'))
        );
        $this->leadAssigner->expects(self::never())->method('assign');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    public function testExecuteSkipsAssignmentWhenCustomerAlreadyHasRep(): void
    {
        $customer = $this->makeCustomer(42);
        $this->leadAssigner->expects(self::once())->method('hasAssignedRep')->with($customer)->willReturn(true);
        $this->leadAssigner->expects(self::never())->method('assign');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    public function testExecuteSkipsAssignmentWhenNoRuleMatches(): void
    {
        $customer = $this->makeCustomer(42);
        $this->leadAssigner->expects(self::once())->method('hasAssignedRep')->with($customer)->willReturn(false);
        $this->leadRoutingRuleEvaluator->method('getMatchingRule')->willReturn(null);
        $this->leadAssigner->expects(self::never())->method('assign');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    public function testExecuteAssignsCustomerToMatchedRule(): void
    {
        $customer = $this->makeCustomer(42);
        $rule = $this->createStub(LeadRoutingRule::class);
        $this->leadAssigner->expects(self::once())->method('hasAssignedRep')->with($customer)->willReturn(false);
        $this->leadRoutingRuleEvaluator->method('getMatchingRule')->willReturn($rule);
        $this->leadAssigner->expects(self::once())->method('assign')->with(42, $rule);

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsIsEmpty(): void
    {
        self::assertSame([], $this->makeAction()->getParamFields());
    }
}
