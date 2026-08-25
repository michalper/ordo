<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Rule\Action\Discount;

use Magento\SalesRule\Model\Rule\Action\Discount\Data as DiscountData;
use Magento\SalesRule\Model\Rule\Action\Discount\DataFactory as DiscountDataFactory;
use Magento\SalesRule\Model\Rule\Action\Discount\DiscountInterface;

/**
 * Registered against Magento\SalesRule\Model\Validator's "calculators" array (see di.xml) as
 * a new possible value for a cart price rule's simple_action — the extension point native
 * "Buy X Get Y" also uses. Gives 100% off exactly one unit of whichever item in the rule's
 * own qualifying set (its normal item-condition tree) is cheapest.
 *
 * KNOWN LIMITATION (see README → Roadmap → Phase 3): the native admin "Apply" dropdown is a
 * hardcoded option list in a core admin block — this calculator works once a rule's
 * simple_action is set to "ordo_cheapest_item_free" (e.g. via the REST API or a direct
 * assignment), but won't appear as a friendly label in the dropdown without a plugin on
 * that core block. Not yet built.
 *
 * VERIFICATION STATUS: implemented against the documented, stable DiscountInterface contract
 * and the standard technique (re-running the rule's own condition tree per item) used by
 * comparable open-source "cheapest item free" extensions — has not been exercised against a
 * real Magento checkout yet. Tracked in Phase 6 as an MFTF scenario before this ships as
 * something a real store should rely on.
 */
class CheapestItemFree implements DiscountInterface
{
    public function __construct(
        private readonly QualifyingSetTracker $qualifyingSetTracker,
        private readonly DiscountDataFactory $discountDataFactory
    ) {
    }

    public function fixQuantity($qty, $rule)
    {
        return $qty;
    }

    public function calculate($rule, $item, $qty)
    {
        /** @var DiscountData $discountData */
        $discountData = $this->discountDataFactory->create();

        if (!$this->qualifyingSetTracker->isFreeItem($rule, $item)) {
            return $discountData;
        }

        // Only one unit of the chosen item is free, regardless of how many units are in the cart.
        $freeQty = min($qty, 1);
        $amount = $item->getCalculationPrice() * $freeQty;
        $baseAmount = $item->getBaseCalculationPrice() * $freeQty;

        $discountData->setAmount($amount);
        $discountData->setBaseAmount($baseAmount);
        $discountData->setOriginalAmount($amount);
        $discountData->setBaseOriginalAmount($baseAmount);

        return $discountData;
    }
}
