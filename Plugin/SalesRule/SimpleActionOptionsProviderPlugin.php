<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\SalesRule;

use Magento\SalesRule\Model\Rule\Action\SimpleActionOptionsProvider;

/**
 * Makes "ordo_cheapest_item_free" selectable from the native admin "Apply" dropdown
 * (Stores > Cart Price Rules > Actions > Apply) instead of only assignable via direct rule
 * data / the REST API. SimpleActionOptionsProvider::toOptionArray() is a plain class, not an
 * interface with a di.xml preference — a plugin on the concrete class is the only extension
 * point Magento core offers here. See README → Roadmap → Phase 3/4 for the history of this
 * known limitation.
 */
class SimpleActionOptionsProviderPlugin
{
    /**
     * @param SimpleActionOptionsProvider $subject
     * @param array $result
     * @return array
     */
    public function afterToOptionArray(SimpleActionOptionsProvider $subject, array $result): array
    {
        $result[] = [
            'label' => __('Cheapest item in qualifying set free (Ordo Automation)'),
            'value' => 'ordo_cheapest_item_free',
        ];

        return $result;
    }
}
