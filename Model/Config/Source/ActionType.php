<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\TypeLabels;

/**
 * See ConditionType — same idea, generated from ActionPool::getAvailableTypes().
 */
class ActionType implements OptionSourceInterface
{
    public function __construct(
        private readonly ActionPool $actionPool,
        private readonly TypeLabels $typeLabels
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->actionPool->getAvailableTypes() as $type) {
            $options[] = ['value' => $type, 'label' => $this->typeLabels->actionLabel($type)];
        }

        return $options;
    }
}
