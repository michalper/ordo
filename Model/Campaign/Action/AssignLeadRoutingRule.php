<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\LeadRouting\LeadAssigner;
use Ordo\Automation\Model\LeadRouting\LeadRoutingRuleEvaluator;
use Ordo\Automation\Model\LeadRoutingRule;

/**
 * A thin wrapper around the exact same LeadRoutingRuleEvaluator::getMatchingRule()/
 * LeadAssigner::assign() pair Observer\AssignLeadRoutingRule already calls from the two
 * hardcoded real-time events (customer_register_success, ordo_customer_score_threshold_crossed)
 * - this is the campaign-engine connector those two never had, so a store can route a lead off
 * ANY trigger a campaign can build on (e.g. "nps_score_at_most" below a detractor survey
 * response), not just those two. No params of its own - same reasoning the observer needs none,
 * the matching rule and its rep pool are looked up fresh every time.
 *
 * LeadAssigner::hasAssignedRep()'s own one-time-assignment guard (re-checked again, under a
 * per-customer lock, inside assign() itself) already protects this from overwriting an existing
 * rep relationship, including one the observer's own real-time events already made - this action
 * is safe to layer onto a campaign without any risk of double-assigning a customer who already
 * has a rep.
 */
class AssignLeadRoutingRule implements ActionInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LeadRoutingRuleEvaluator $leadRoutingRuleEvaluator,
        private readonly LeadAssigner $leadAssigner
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable) {
            return;
        }

        if ($this->leadAssigner->hasAssignedRep($customer)) {
            return;
        }

        $rule = $this->leadRoutingRuleEvaluator->getMatchingRule($customer);
        if (!$rule instanceof LeadRoutingRule) {
            return;
        }

        $this->leadAssigner->assign($customerId, $rule);
    }

    public function getParamFields(): array
    {
        return [];
    }
}
