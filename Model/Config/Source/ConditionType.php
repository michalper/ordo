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
 */
class ConditionType implements OptionSourceInterface
{
    public function __construct(
        private readonly ConditionPool $conditionPool,
        private readonly TypeLabels $typeLabels
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->conditionPool->getAvailableTypes() as $type) {
            $options[] = ['value' => $type, 'label' => $this->typeLabels->conditionLabel($type)];
        }

        return $options;
    }
}
