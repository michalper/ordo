<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\ScoreRule\ScoreRuleEvaluator;
use Psr\Log\LoggerInterface;

/**
 * Fires on every customer_save_after: re-evaluates the demographic scoring rules against the
 * saved customer, applies the delta between the old and new demographic score to the
 * customer's running lead score total, and — if that push carries the total across the
 * configured threshold — dispatches the "ordo_customer_score_threshold_crossed" Magento event
 * (same pattern as CustomerTagManager::addTag(), see its class doc), which
 * DispatchScoreThresholdCampaigns turns into a campaign dispatch.
 *
 * Everything below the config check is wrapped in a try/catch — same defensive posture as
 * Observer\HoldOrderForApproval's own email-sending try/catch: this observer fires on literally
 * every customer save (checkout, registration, admin edit, REST API), so a bug here must never
 * be able to break customer save for the whole application. Confirmed necessary via a real CI
 * run: enabling lead scoring made POST /V1/customers itself start returning 400 for every
 * customer, with nothing logged anywhere — customer_save_after's "customer" event data is not
 * guaranteed to be a CustomerInterface (it's the legacy Magento\Customer\Model\Customer, whose
 * Interceptor doesn't implement CustomerInterface either), so a CustomerInterface type-hint on
 * that object throws a TypeError before any of our code even runs. Re-fetching a real
 * CustomerInterface via CustomerRepositoryInterface::getById() (same id) sidesteps that
 * entirely instead of trusting the event payload's concrete type.
 */
class EvaluateCustomerScoreRules implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ScoreRuleEvaluator $scoreRuleEvaluator,
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly EventManagerInterface $eventManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        if (!$this->config->isLeadScoringEnabled()) {
            return;
        }

        $eventCustomer = $observer->getEvent()->getData('customer');
        $rawId = match (true) {
            $eventCustomer instanceof \Magento\Framework\DataObject => $eventCustomer->getId(),
            $eventCustomer instanceof CustomerInterface => $eventCustomer->getId(),
            default => null,
        };
        $customerId = is_scalar($rawId) ? (int) $rawId : 0;
        if (!$customerId) {
            return;
        }

        try {
            $this->evaluate($customerId, $this->customerRepository->getById($customerId));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: lead-scoring evaluation failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
        }
    }

    private function evaluate(int $customerId, CustomerInterface $customer): void
    {
        $newDemographicScore = $this->scoreRuleEvaluator->getMatchingRulePoints($customer);
        $oldDemographicScore = $this->customerScoreManager->getDemographicScore($customerId);

        $delta = $newDemographicScore - $oldDemographicScore;
        if ($delta === 0) {
            return;
        }

        $scoreBefore = $this->customerScoreManager->getScore($customerId);
        $this->customerScoreManager->addPoints($customerId, $delta);
        $this->customerScoreManager->setDemographicScore($customerId, $newDemographicScore);
        $scoreAfter = $scoreBefore + $delta;

        $threshold = $this->config->getScoreThreshold();
        if ($scoreBefore < $threshold && $scoreAfter >= $threshold) {
            $this->eventManager->dispatch('ordo_customer_score_threshold_crossed', ['customer_id' => $customerId]);
        }
    }
}
