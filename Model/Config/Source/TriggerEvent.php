<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;

class TriggerEvent implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => CampaignTriggerInterface::TRIGGER_ORDER_PLACED, 'label' => __('Order Placed')],
            ['value' => CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED, 'label' => __('Customer Registered')],
            ['value' => CampaignTriggerInterface::TRIGGER_TAG_ADDED, 'label' => __('Tag Added')],
            ['value' => CampaignTriggerInterface::TRIGGER_CART_ABANDONED, 'label' => __('Cart Abandoned')],
            [
                'value' => CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED,
                'label' => __('Visitor Tag Added (anonymous)'),
            ],
        ];
    }
}
