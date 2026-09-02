<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\ScoreRule\ScoreRuleEvaluator;
use Ordo\Automation\Observer\EvaluateCustomerScoreRules;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EvaluateCustomerScoreRulesTest extends TestCase
{
    private Config $config;
    private ScoreRuleEvaluator $scoreRuleEvaluator;
    private CustomerScoreManager $customerScoreManager;
    private EventManagerInterface $eventManager;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->scoreRuleEvaluator = $this->createStub(ScoreRuleEvaluator::class);
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function makeObserver(?CustomerInterface $customer): EventObserver
    {
        $event = new Event(['customer' => $customer]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    /**
     * Also wires customerRepository->getById($id) to return this same stub — matching the
     * observer re-fetching a real CustomerInterface via the repository instead of trusting the
     * customer_save_after event payload's own (possibly non-CustomerInterface) type.
     */
    private function makeCustomer(int $id): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $this->customerRepository->method('getById')->willReturn($customer);

        return $customer;
    }

    private function makeObserverInstance(): EvaluateCustomerScoreRules
    {
        return new EvaluateCustomerScoreRules(
            $this->config,
            $this->scoreRuleEvaluator,
            $this->customerScoreManager,
            $this->eventManager,
            $this->customerRepository,
            $this->logger
        );
    }

    public function testExecuteDoesNothingWhenLeadScoringDisabled(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(false);

        $this->customerScoreManager->expects(self::never())->method('addPoints');
        $this->eventManager->expects(self::never())->method('dispatch');

        $observer = $this->makeObserver($this->makeCustomer(42));

        $this->makeObserverInstance()->execute($observer);
    }

    public function testExecuteDoesNothingWhenDeltaIsZero(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(true);
        $customer = $this->makeCustomer(42);
        $this->scoreRuleEvaluator->method('getMatchingRulePoints')->willReturn(10);
        $this->customerScoreManager->method('getDemographicScore')->with(42)->willReturn(10);

        $this->customerScoreManager->expects(self::never())->method('addPoints');
        $this->customerScoreManager->expects(self::never())->method('setDemographicScore');
        $this->eventManager->expects(self::never())->method('dispatch');

        $this->makeObserverInstance()->execute($this->makeObserver($customer));
    }

    public function testExecuteAppliesNegativeDeltaWithoutCrossingThreshold(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(true);
        $this->config->method('getScoreThreshold')->willReturn(100);
        $customer = $this->makeCustomer(42);
        $this->scoreRuleEvaluator->method('getMatchingRulePoints')->willReturn(5);
        $this->customerScoreManager->method('getDemographicScore')->with(42)->willReturn(15);
        $this->customerScoreManager->method('getScore')->with(42)->willReturn(50);

        $this->customerScoreManager->expects(self::once())->method('addPoints')->with(42, -10);
        $this->customerScoreManager->expects(self::once())->method('setDemographicScore')->with(42, 5);
        $this->eventManager->expects(self::never())->method('dispatch');

        $this->makeObserverInstance()->execute($this->makeObserver($customer));
    }

    public function testExecuteDispatchesEventWhenThresholdGenuinelyCrossed(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(true);
        $this->config->method('getScoreThreshold')->willReturn(100);
        $customer = $this->makeCustomer(42);
        $this->scoreRuleEvaluator->method('getMatchingRulePoints')->willReturn(30);
        $this->customerScoreManager->method('getDemographicScore')->with(42)->willReturn(0);
        $this->customerScoreManager->method('getScore')->with(42)->willReturn(90);

        $this->customerScoreManager->expects(self::once())->method('addPoints')->with(42, 30);

        $this->eventManager->expects(self::once())->method('dispatch')->with(
            'ordo_customer_score_threshold_crossed',
            ['customer_id' => 42]
        );

        $this->makeObserverInstance()->execute($this->makeObserver($customer));
    }

    public function testExecuteDoesNotDispatchWhenAlreadyOverThreshold(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(true);
        $this->config->method('getScoreThreshold')->willReturn(100);
        $customer = $this->makeCustomer(42);
        $this->scoreRuleEvaluator->method('getMatchingRulePoints')->willReturn(30);
        $this->customerScoreManager->method('getDemographicScore')->with(42)->willReturn(10);
        $this->customerScoreManager->method('getScore')->with(42)->willReturn(150);

        $this->eventManager->expects(self::never())->method('dispatch');

        $this->makeObserverInstance()->execute($this->makeObserver($customer));
    }

    public function testExecuteDoesNotDispatchWhenStillUnderThreshold(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(true);
        $this->config->method('getScoreThreshold')->willReturn(100);
        $customer = $this->makeCustomer(42);
        $this->scoreRuleEvaluator->method('getMatchingRulePoints')->willReturn(15);
        $this->customerScoreManager->method('getDemographicScore')->with(42)->willReturn(10);
        $this->customerScoreManager->method('getScore')->with(42)->willReturn(20);

        $this->eventManager->expects(self::never())->method('dispatch');

        $this->makeObserverInstance()->execute($this->makeObserver($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenCustomerMissing(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(true);

        $this->customerScoreManager->expects(self::never())->method('addPoints');

        $this->makeObserverInstance()->execute($this->makeObserver(null));
    }

    /**
     * The whole reason for the try/catch — this observer fires on every single customer save
     * across the entire application (checkout, registration, admin edit, REST API), so a bug
     * here (e.g. the event's "customer" not actually being a full CustomerInterface — see the
     * class doc) must never be able to break customer save itself. Confirmed necessary via a
     * real CI regression: enabling lead scoring made POST /V1/customers start returning 400 for
     * every customer, with nothing logged anywhere, before this try/catch existed.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCatchesAndLogsInsteadOfLettingCustomerSaveBreak(): void
    {
        $this->config->method('isLeadScoringEnabled')->willReturn(true);
        $customer = $this->makeCustomer(42);
        $this->scoreRuleEvaluator->method('getMatchingRulePoints')
            ->willThrowException(new \Error('Call to undefined method on customer'));
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->logger->expects(self::once())->method('error')->with(
            self::stringContains('lead-scoring evaluation failed for customer #42')
        );

        // The real assertion: execute() itself must not throw.
        $this->makeObserverInstance()->execute($this->makeObserver($customer));
    }
}
