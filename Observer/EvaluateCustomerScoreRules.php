<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\ScoreRule\ScoreRuleEvaluator;

/**
 * Fires on every customer_save_after: re-evaluates the demographic scoring rules against the
 * saved customer, applies the delta between the old and new demographic score to the
 * customer's running lead score total, and — if that push carries the total across the
 * configured threshold — dispatches the "ordo_customer_score_threshold_crossed" Magento event
 * (same pattern as CustomerTagManager::addTag(), see its class doc), which
 * DispatchScoreThresholdCampaigns turns into a campaign dispatch.
 */
class EvaluateCustomerScoreRules implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ScoreRuleEvaluator $scoreRuleEvaluator,
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly EventManagerInterface $eventManager
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        if (!$this->config->isLeadScoringEnabled()) {
            return;
        }

        /** @var CustomerInterface|null $customer */
        $customer = $observer->getEvent()->getCustomer();
        if (!$customer || !$customer->getId()) {
            return;
        }

        $customerId = (int) $customer->getId();

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
