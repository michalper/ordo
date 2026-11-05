<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as SalesRuleCollectionFactory;
use Magento\SalesRule\Model\Rule;

/**
 * Every active cart price rule that actually uses a coupon code (excludes "No Coupon" rules,
 * which apply automatically and would make a Model\CouponGenerator-minted code for one a no-op -
 * the rule already discounts without it) - the generate_coupon campaign action's own rule_id
 * field, and the content block product feed's rule-based source. Mirrors CampaignOptions.php's
 * shape.
 */
class CartPriceRuleOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly SalesRuleCollectionFactory $salesRuleCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->salesRuleCollectionFactory->create();
        $collection->addFieldToFilter('is_active', '1');
        $collection->addFieldToFilter('coupon_type', ['neq' => Rule::COUPON_TYPE_NO_COUPON]);

        $options = [];
        foreach ($collection as $rule) {
            /** @var Rule $rule */
            $options[] = ['value' => (int) $rule->getId(), 'label' => (string) $rule->getData('name')];
        }

        return $options;
    }
}
