<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\MessageLogEvent;

/**
 * Options for the message_engaged condition's event_type field - every event type a delivery-
 * status webhook actually writes to ordo_message_log_event: Controller\Email\StatusCallback
 * writes Opened/Clicked, Controller\WhatsApp\Webhook writes Read (WhatsApp's own "seen" receipt).
 */
class MessageEngagementType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => MessageLogEvent::TYPE_OPENED, 'label' => __('Opened')],
            ['value' => MessageLogEvent::TYPE_CLICKED, 'label' => __('Clicked')],
            ['value' => MessageLogEvent::TYPE_READ, 'label' => __('Read (WhatsApp)')],
        ];
    }
}
