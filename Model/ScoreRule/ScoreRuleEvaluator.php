<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ScoreRule;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\CollectionFactory as ScoreRuleWebsiteCollectionFactory;
use Ordo\Automation\Model\ScoreRule;

/**
 * Sums the points of every enabled ordo_score_rule that matches a given customer's demographic
 * attributes. A handful of attribute codes are core CustomerInterface getters (group_id,
 * website_id, email, store_id); anything else falls back to the customer's EAV custom
 * attributes via getCustomAttribute(). A rule referencing an attribute the customer doesn't
 * have (typo'd code, attribute removed, etc.) simply never matches — no exception, no log,
 * that's expected admin-configuration UX, not an error condition. The one exception is
 * not_equals: a missing attribute counts as a match there too, same as a value that's merely
 * different from the one configured (see matches()).
 */
class ScoreRuleEvaluator
{
    private const string OPERATOR_EQUALS = 'equals';
    private const string OPERATOR_NOT_EQUALS = 'not_equals';
    private const string OPERATOR_CONTAINS = 'contains';

    public function __construct(
        private readonly ScoreRuleCollectionFactory $scoreRuleCollectionFactory,
        private readonly ScoreRuleWebsiteCollectionFactory $scoreRuleWebsiteCollectionFactory,
        private readonly CustomerStoreResolver $customerStoreResolver
    ) {
    }

    public function getMatchingRulePoints(CustomerInterface $customer): int
    {
        $collection = $this->scoreRuleCollectionFactory->create();
        $collection->addFieldToFilter('enabled', 1);

        $rules = [];
        foreach ($collection as $rule) {
            /** @var ScoreRule $rule */
            $rules[] = $rule;
        }

        $websiteIdsByRule = $this->loadWebsiteIdsByRule($rules);
        $customerWebsiteId = $websiteIdsByRule === []
            ? null
            : $this->customerStoreResolver->resolveWebsiteId($customer);

        $points = 0;
        foreach ($rules as $rule) {
            $ruleWebsiteIds = $websiteIdsByRule[(int) $rule->getEntityId()] ?? [];

            // A rule with no website rows (the common case) is unscoped - matches regardless.
            // A website-scoped rule can't be verified without a resolved customer website id,
            // so it's skipped rather than matched - same "can't verify, don't run" philosophy
            // every other website-scoping guard in this module (CampaignDispatcher,
            // SegmentMemberResolver) already follows, see docs/adr/0014.
            if ($ruleWebsiteIds !== [] && (
                $customerWebsiteId === null || !in_array($customerWebsiteId, $ruleWebsiteIds, true)
            )) {
                continue;
            }

            if ($this->matches($customer, $rule)) {
                $points += $rule->getPoints();
            }
        }

        return $points;
    }

    /**
     * One batch query for every candidate rule's website rows, not one query per rule - same
     * "one query, not N" reasoning as every other batch load in this module.
     *
     * @param ScoreRule[] $rules
     * @return array<int, int[]>
     */
    private function loadWebsiteIdsByRule(array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $ruleIds = array_map(static fn (ScoreRule $rule): int => (int) $rule->getEntityId(), $rules);

        $websiteIdsByRule = [];
        $websiteRows = $this->scoreRuleWebsiteCollectionFactory->create();
        $websiteRows->addScoreRuleIdsFilter($ruleIds);
        foreach ($websiteRows as $row) {
            /** @var \Ordo\Automation\Model\ScoreRuleWebsite $row */
            $websiteIdsByRule[$row->getScoreRuleId()][] = $row->getWebsiteId();
        }

        return $websiteIdsByRule;
    }

    private function matches(CustomerInterface $customer, ScoreRule $rule): bool
    {
        $attributeValue = $this->getAttributeValue($customer, $rule->getAttributeCode());

        if ($attributeValue === null) {
            // Every other operator needs a real value to compare against, but not_equals is the
            // one case where "the customer doesn't even have this attribute" should still count
            // as a match - a rule reading "loyalty_tier not_equals gold" is meant to catch every
            // customer who isn't tier-gold, and a customer with no tier at all plainly isn't.
            // Reported directly: this early return used to apply to not_equals too, silently
            // failing to match every customer missing the attribute instead of scoring them.
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
