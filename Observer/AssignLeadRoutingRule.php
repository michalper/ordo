<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\LeadRouting\LeadAssigner;
use Ordo\Automation\Model\LeadRouting\LeadRoutingRuleEvaluator;
use Ordo\Automation\Model\LeadRoutingRule;
use Psr\Log\LoggerInterface;

/**
 * Fires on the two real-time "lead just qualified" moments this module already has a Magento
 * event for: customer_register_success (a brand-new lead) and
 * ordo_customer_score_threshold_crossed (an existing lead who just became sales-ready) - see
 * Observer\EvaluateCustomerScoreRules for where the latter is dispatched. Finds the first
 * matching ordo_lead_routing_rule (LeadRoutingRuleEvaluator) and hands off to LeadAssigner,
 * which picks the next rep from that rule's own round-robin pool.
 *
 * Deliberately a ONE-TIME assignment, not a reassignment engine: LeadAssigner::hasAssignedRep()
 * skips a customer who already has a rep (whether from an earlier automatic assignment or a
 * manual admin edit) - a later qualifying event must never silently overwrite an existing
 * relationship.
 *
 * Same defensive shape as EvaluateCustomerScoreRules: config check first, re-fetches a real
 * CustomerInterface via CustomerRepositoryInterface::getById() rather than trusting either
 * event's payload concrete type (customer_register_success carries the legacy
 * Magento\Customer\Model\Customer, not CustomerInterface, same landmine documented on that
 * observer), and wraps everything below the config check in try/catch + logger so a bug here
 * can never break registration or the score-threshold campaign dispatch.
 */
class AssignLeadRoutingRule implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly LeadRoutingRuleEvaluator $leadRoutingRuleEvaluator,
        private readonly LeadAssigner $leadAssigner,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        if (!$this->config->isLeadRoutingEnabled()) {
            return;
        }

        $customerId = $this->resolveCustomerId($observer);
        if (!$customerId) {
            return;
        }

        try {
            $this->evaluate($customerId, $this->customerRepository->getById($customerId));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: lead-routing assignment failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
        }
    }

    private function resolveCustomerId(EventObserver $observer): int
    {
        $event = $observer->getEvent();

        $rawCustomerId = $event->getData('customer_id');
        if (is_scalar($rawCustomerId) && (int) $rawCustomerId > 0) {
            return (int) $rawCustomerId;
        }

        $eventCustomer = $event->getData('customer');
        $rawId = match (true) {
            $eventCustomer instanceof \Magento\Framework\DataObject => $eventCustomer->getId(),
            $eventCustomer instanceof CustomerInterface => $eventCustomer->getId(),
            default => null,
        };

        return is_scalar($rawId) ? (int) $rawId : 0;
    }

    private function evaluate(int $customerId, CustomerInterface $customer): void
    {
        if ($this->leadAssigner->hasAssignedRep($customer)) {
            return;
        }

        $rule = $this->leadRoutingRuleEvaluator->getMatchingRule($customer);
        if (!$rule instanceof LeadRoutingRule) {
            return;
        }

        $this->leadAssigner->assign($customerId, $rule);
    }
}
