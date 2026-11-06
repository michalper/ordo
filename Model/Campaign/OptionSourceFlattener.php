<?php

declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The canvas (Block\Adminhtml\Campaign\Edit\Flow) renders plain value => label maps; the admin
 * form's Source classes return OptionSourceInterface's list-of-pairs shape. One flattener,
 * shared by every condition/action class whose getParamFields() needs to flatten one, instead of
 * each one re-implementing the same loop (previously this lived only on Flow itself, as a
 * private toMap() method, before condition/action classes needed to flatten their own options
 * too).
 */
class OptionSourceFlattener
{
    /**
     * @return array<int|string, string>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure value transform, nothing to intercept
    public static function toMap(OptionSourceInterface $source): array
    {
        $map = [];
        foreach ($source->toOptionArray() as $option) {
            $map[$option['value']] = (string) $option['label'];
        }

        return $map;
    }
}
