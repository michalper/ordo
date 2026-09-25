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
                'value' => CampaignTriggerInterface::TRIGGER_BROWSE_ABANDONED,
                'label' => __('Browse Abandoned (viewed a product, no order since)'),
            ],
            [
                'value' => CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED,
                'label' => __('Visitor Tag Added (anonymous)'),
            ],
            [
                'value' => CampaignTriggerInterface::TRIGGER_SCORE_THRESHOLD_CROSSED,
                'label' => __('Score Threshold Crossed'),
            ],
            ['value' => CampaignTriggerInterface::TRIGGER_SCHEDULED_AT, 'label' => __('Scheduled Date/Time')],
            [
                'value' => CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE,
                'label' => __('Recurring Schedule'),
            ],
            [
                'value' => CampaignTriggerInterface::TRIGGER_WEBHOOK_RECEIVED,
                'label' => __('Webhook Received (signed, from an external system)'),
            ],
            ['value' => CampaignTriggerInterface::TRIGGER_PRICE_DROP, 'label' => __('Price Drop')],
            ['value' => CampaignTriggerInterface::TRIGGER_BACK_IN_STOCK, 'label' => __('Back In Stock')],
            [
                'value' => CampaignTriggerInterface::TRIGGER_REVIEW_REQUEST_DUE,
                'label' => __('Review Request Due (N days after a completed order)'),
            ],
            [
                'value' => CampaignTriggerInterface::TRIGGER_REFERRAL_CONVERTED,
                'label' => __('Referral Converted (referred customer placed their first order)'),
            ],
        ];
    }
}
