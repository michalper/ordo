<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\SalesRule\Model\RuleFactory;

/**
 * SKUs of catalog products matching a cart price rule's "Apply the rule to cart items matching
 * these conditions" (the rule's "Actions" tab, confirmed against
 * Magento\SalesRule\Model\Validator — canApplyDiscount()/collectDiscountAmounts() both call
 * $rule->getActions()->validate($item), never $rule->getConditions() for per-item filtering;
 * getConditions() is the order-level condition set (subtotal, coupon, etc.), while getActions()
 * is hydrated from actions_serialized as a Rule\Condition\Product\Combine — see
 * Model\Rule::getActionsInstance()) — the "source": "rule" branch of a product_feed content
 * block.
 *
 * There is no bulk "evaluate this combine against N products at once" API on
 * Rule\Condition\Product\Combine — it validates one product/item at a time
 * (Rule\Condition\Product::validate(\Magento\Framework\Model\AbstractModel $model), which reads
 * $model->getProduct() first before falling back to a repository lookup by $model->getProductId()
 * — see that class's docblock). Passing a Product instance directly as $model with its own
 * "product" data key set to itself satisfies that check without needing a fake quote item.
 *
 * Scans the catalog page by page (self::PAGE_SIZE at a time), stopping once $limit matches are
 * found or self::MAX_SCANNED products have been examined — same bounded-scan discipline as
 * RfmCalculator/ProductRecommender elsewhere in this module, since a rule with a broad/no
 * condition could otherwise force a full-catalog scan on every content block resolve.
 */
class RuleProductLister
{
    private const PAGE_SIZE = 200;

    private const MAX_SCANNED = 2000;

    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * @return string[]
     */
    public function getSkus(int $ruleId, int $limit): array
    {
        if ($ruleId <= 0 || $limit <= 0) {
            return [];
        }

        $rule = $this->ruleFactory->create();
        $rule->load($ruleId);
        if (!$rule->getRuleId()) {
            return [];
        }

        $combine = $rule->getActions();

        $skus = [];
        $scanned = 0;
        $page = 1;

        while (count($skus) < $limit && $scanned < self::MAX_SCANNED) {
            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect('*');
            $collection->setPageSize(self::PAGE_SIZE);
            $collection->setCurPage($page);

            $items = $collection->getItems();
            if ($items === []) {
                break;
            }

            foreach ($items as $product) {
                if (count($skus) >= $limit) {
                    break;
                }

                $scanned++;

                // Rule\Condition\Product::validate() calls $model->getProduct() before falling
                // back to a repository lookup — pointing that back at the product itself avoids
                // both a redundant lookup and needing a fake quote item.
                $product->setData('product', $product);

                if ($combine->validate($product)) {
                    $skus[] = (string) $product->getSku();
                }

                if ($scanned >= self::MAX_SCANNED) {
                    break;
                }
            }

            if (count($items) < self::PAGE_SIZE) {
                break;
            }

            $page++;
        }

        return $skus;
    }
}
