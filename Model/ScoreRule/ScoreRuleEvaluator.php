<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ScoreRule;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ScoreRule;

/**
 * Sums the points of every enabled ordo_score_rule that matches a given customer's demographic
 * attributes. A handful of attribute codes are core CustomerInterface getters (group_id,
 * website_id, email, store_id); anything else falls back to the customer's EAV custom
 * attributes via getCustomAttribute(). A rule referencing an attribute the customer doesn't
 * have (typo'd code, attribute removed, etc.) simply never matches — no exception, no log,
 * that's expected admin-configuration UX, not an error condition.
 */
class ScoreRuleEvaluator
{
    private const OPERATOR_EQUALS = 'equals';
    private const OPERATOR_NOT_EQUALS = 'not_equals';
    private const OPERATOR_CONTAINS = 'contains';

    public function __construct(
        private readonly ScoreRuleCollectionFactory $scoreRuleCollectionFactory
    ) {
    }

    public function getMatchingRulePoints(CustomerInterface $customer): int
    {
        $collection = $this->scoreRuleCollectionFactory->create();
        $collection->addFieldToFilter('enabled', 1);

        $points = 0;
        foreach ($collection as $rule) {
            /** @var ScoreRule $rule */
            if ($this->matches($customer, $rule)) {
                $points += $rule->getPoints();
            }
        }

        return $points;
    }

    private function matches(CustomerInterface $customer, ScoreRule $rule): bool
    {
        $attributeValue = $this->getAttributeValue($customer, $rule->getAttributeCode());
        if ($attributeValue === null) {
            return false;
        }

        $actual = (string) $attributeValue;
        $expected = $rule->getValue();

        return match ($rule->getOperator()) {
            self::OPERATOR_EQUALS => $actual === $expected,
            self::OPERATOR_NOT_EQUALS => $actual !== $expected,
            self::OPERATOR_CONTAINS => str_contains($actual, $expected),
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
