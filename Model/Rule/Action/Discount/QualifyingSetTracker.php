<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Rule\Action\Discount;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;

/**
 * Magento's SalesRule engine calls DiscountInterface::calculate() once per matching item,
 * one at a time — it never hands a calculator "all the items this rule matched" as a set.
 * "Give the cheapest item in the qualifying set for free" is inherently a cross-item decision,
 * so this tracker recomputes the qualifying set (by re-running the rule's own item conditions
 * against every item in the quote) the first time any item for a given rule+quote is asked
 * about in a request, then caches which single item was chosen — so all the individual
 * per-item calculate() calls agree on the same answer within one total-collection pass.
 *
 * Request-scoped only (plain property, not persisted) — a fresh totals collection starts
 * with an empty cache, which is correct: the qualifying set can change between requests as
 * the cart changes.
 */
class QualifyingSetTracker
{
    /**
     * @var array<string, int|null> "{ruleId}:{quoteId}" => item id chosen as free, or null if none qualified
     */
    private array $freeItemIdByRuleAndQuote = [];

    public function isFreeItem(Rule $rule, AbstractItem $item): bool
    {
        $key = $rule->getRuleId() . ':' . $item->getQuoteId();

        if (!array_key_exists($key, $this->freeItemIdByRuleAndQuote)) {
            $this->freeItemIdByRuleAndQuote[$key] = $this->resolveCheapestQualifyingItemId($rule, $item);
        }

        return $this->freeItemIdByRuleAndQuote[$key] !== null
            && $this->freeItemIdByRuleAndQuote[$key] === (int) $item->getItemId();
    }

    private function resolveCheapestQualifyingItemId(Rule $rule, AbstractItem $item): ?int
    {
        $conditions = $rule->getConditions();
        $cheapest = null;

        foreach ($item->getQuote()->getAllItems() as $candidate) {
            if (!$conditions->validate($candidate)) {
                continue;
            }

            if ($cheapest === null || $candidate->getCalculationPrice() < $cheapest->getCalculationPrice()) {
                $cheapest = $candidate;
            }
        }

        return $cheapest ? (int) $cheapest->getItemId() : null;
    }
}
