<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How a product_feed content block picks its products — either a fixed category or a
 * cart price rule (Magento\SalesRule, matched via RuleProductLister against the rule's
 * product condition). See ordo_contentblock_form.xml's nested switcherConfig on the "source"
 * field for how this toggles category_id vs rule_id.
 */
class ProductFeedSourceType implements OptionSourceInterface
{
    public const CATEGORY = 'category';
    public const RULE = 'rule';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::CATEGORY, 'label' => __('Category')],
            ['value' => self::RULE, 'label' => __('Cart Price Rule')],
        ];
    }
}
