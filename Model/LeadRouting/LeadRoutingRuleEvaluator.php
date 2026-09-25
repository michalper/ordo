<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\LeadRouting;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory as LeadRoutingRuleCollectionFactory;

/**
 * Finds the first enabled ordo_lead_routing_rule (in sort_order) that matches a given customer -
 * first-match-wins, unlike ScoreRuleEvaluator's sum-of-points-across-every-match, since a lead
 * gets routed to exactly one rep pool, not scored cumulatively. Matching itself (equals/
 * not_equals/contains against a core CustomerInterface getter or an EAV custom attribute) is a
 * deliberate near-duplicate of ScoreRuleEvaluator::matches()/getAttributeValue() - kept as its
 * own small copy rather than a shared abstraction, same as every other small matcher in this
 * module (campaign conditions, segment conditions) already does.
 *
 * A blank attribute_code always matches - lets an admin define a catch-all "default pool" rule
 * at the bottom of sort_order for any customer no earlier rule claimed.
 */
class LeadRoutingRuleEvaluator
{
    private const string OPERATOR_EQUALS = 'equals';
    private const string OPERATOR_NOT_EQUALS = 'not_equals';
    private const string OPERATOR_CONTAINS = 'contains';

    public function __construct(
        private readonly LeadRoutingRuleCollectionFactory $leadRoutingRuleCollectionFactory
    ) {
    }

    public function getMatchingRule(CustomerInterface $customer): ?LeadRoutingRule
    {
        $collection = $this->leadRoutingRuleCollectionFactory->create();
        $collection->addFieldToFilter('enabled', 1);
        $collection->setOrder('sort_order', 'ASC');

        foreach ($collection as $rule) {
            /** @var LeadRoutingRule $rule */
            if ($this->matches($customer, $rule)) {
                return $rule;
            }
        }

        return null;
    }

    private function matches(CustomerInterface $customer, LeadRoutingRule $rule): bool
    {
        $attributeCode = $rule->getAttributeCode();
        if ($attributeCode === '') {
            return true;
        }

        $attributeValue = $this->getAttributeValue($customer, $attributeCode);

        if ($attributeValue === null) {
            // Every other operator needs a real value to compare against, but not_equals is the
            // one case where "the customer doesn't even have this attribute" should still count
            // as a match - a rule reading "loyalty_tier not_equals gold" is meant to route every
            // customer who isn't tier-gold, and a customer with no tier at all plainly isn't.
            // Reported directly: this early return used to apply to not_equals too, silently
            // skipping every customer missing the attribute instead of routing them.
            return $rule->getOperator() === self::OPERATOR_NOT_EQUALS;
        }

        $expected = $rule->getValue();

        return match ($rule->getOperator()) {
            self::OPERATOR_EQUALS => $attributeValue === $expected,
            self::OPERATOR_NOT_EQUALS => $attributeValue !== $expected,
            self::OPERATOR_CONTAINS => str_contains($attributeValue, $expected),
            default => false,
        };
    }

    private function getAttributeValue(CustomerInterface $customer, string $attributeCode): ?string
    {
        $value = match ($attributeCode) {
            'group_id' => $customer->getGroupId(),
            'website_id' => $customer->getWebsiteId(),
            'email' => $customer->getEmail(),
            'store_id' => $customer->getStoreId(),
            default => null,
        };

        if ($value !== null) {
            return (string) $value;
        }

        $customAttribute = $customer->getCustomAttribute($attributeCode);

        return $customAttribute !== null ? (string) $customAttribute->getValue() : null;
    }
}
