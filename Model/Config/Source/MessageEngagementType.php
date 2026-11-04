<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\MessageLogEvent;

/**
 * Options for the message_engaged condition's event_type field - the two event types
 * Controller\Email\StatusCallback actually writes to ordo_message_log_event.
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
        ];
    }
}
