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
     * @var array<string, string|null> "{ruleId}:{quoteId}" => sku chosen as free, or null if none qualified
     */
    private array $freeSkuByRuleAndQuote = [];

    public function isFreeItem(Rule $rule, AbstractItem $item): bool
    {
        $key = $rule->getId() . ':' . $item->getQuote()->getId();

        if (!array_key_exists($key, $this->freeSkuByRuleAndQuote)) {
            $this->freeSkuByRuleAndQuote[$key] = $this->resolveCheapestQualifyingSku($rule, $item);
        }

        $resolvedSku = $this->freeSkuByRuleAndQuote[$key];

        return $resolvedSku !== null && $resolvedSku === $item->getSku();
    }

    /**
     * Identifies the chosen item by SKU, not item id. `calculate()` is called with a
     * `Quote\Address\Item` during real discount collection, and `getItemId()` is reliably
     * null there — `Address\Item::importQuoteItem()` copies the source id into
     * `quote_item_id`, not `item_id` — but that turned out unreliable too: at the point
     * this tracker runs, even the underlying `Quote\Item` objects reached via
     * `$item->getQuote()->getAllItems()` have a null item id (this quote hasn't fully
     * round-tripped through the DB in this request yet). SKU is present and stable in both
     * cases, and — since Magento merges a repeat `addProduct()` call for the same SKU into
     * one line item by default — is a reliable stand-in for "this exact line item" for the
     * qualifying-set use case here.
     */
    private function resolveCheapestQualifyingSku(Rule $rule, AbstractItem $item): ?string
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

        return $cheapest ? $cheapest->getSku() : null;
    }
}
