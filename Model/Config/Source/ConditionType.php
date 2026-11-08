<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Campaign\TypeLabels;

/**
 * The dropdown is generated from ConditionPool::getAvailableTypes() — i.e. from whatever is
 * actually registered in di.xml — so this never drifts out of sync with what the dispatcher
 * can resolve. Adding a condition type makes it show up here automatically.
 *
 * Grouped by TypeLabels::conditionCategory() into Magento_Ui's native <optgroup> shape (a
 * nested array as a "value" means "this is a group header", see
 * Magento_Ui/js/lib/knockout/bindings/optgroup.js - the same OptionSourceInterface array shape
 * every other option source already returns, just with one level of nesting) - reported
 * directly against a real screenshot: one flat ~20-item list wasn't great UX for finding the
 * right condition.
 */
class ConditionType implements OptionSourceInterface
{
    public function __construct(
        private readonly ConditionPool $conditionPool,
        private readonly TypeLabels $typeLabels
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}|array{
     *     value: array<int, array{value: string, label: string}>, label: string
     * }>
     */
    public function toOptionArray(): array
    {
        $typesByCategory = [];
        foreach ($this->conditionPool->getAvailableTypes() as $type) {
            $category = $this->typeLabels->conditionCategory($type);
            $typesByCategory[$category][] = ['value' => $type, 'label' => $this->typeLabels->conditionLabel($type)];
        }

        $options = [];
        foreach ($this->typeLabels->conditionCategoryOrder() as $category) {
            if (!empty($typesByCategory[$category])) {
                $options[] = ['value' => $typesByCategory[$category], 'label' => $category];
            }
        }

        return $options;
    }
}
