<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;

/**
 * The Price Watch Subscriptions grid's "Watch Type" column filter - matches
 * PriceWatchSubscription::WATCH_TYPE_* exactly rather than a second hand-typed list.
 */
class PriceWatchType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 'label' => __('Price Drop')],
            ['value' => PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK, 'label' => __('Back In Stock')],
        ];
    }
}
