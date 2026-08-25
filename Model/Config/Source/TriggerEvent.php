<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Api\Data\CampaignInterface;

class TriggerEvent implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => CampaignInterface::TRIGGER_ORDER_PLACED, 'label' => __('Order Placed')],
            ['value' => CampaignInterface::TRIGGER_CUSTOMER_REGISTERED, 'label' => __('Customer Registered')],
            ['value' => CampaignInterface::TRIGGER_TAG_ADDED, 'label' => __('Tag Added')],
            ['value' => CampaignInterface::TRIGGER_CART_ABANDONED, 'label' => __('Cart Abandoned')],
        ];
    }
}
