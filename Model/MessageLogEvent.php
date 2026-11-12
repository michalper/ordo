<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\MessageLogEvent as MessageLogEventResource;

/**
 * One row per open/click event a delivery-status webhook reported for a given MessageLog row —
 * see etc/db_schema.xml's ordo_message_log_event comment for why this is a separate event log
 * rather than another MessageLog::STATUS_* value.
 */
class MessageLogEvent extends AbstractModel
{
    public const string TYPE_OPENED = 'opened';
    public const string TYPE_CLICKED = 'clicked';

    /**
     * WhatsApp's own "seen" receipt (Meta Cloud API's "read" message status, see
     * Controller\WhatsApp\Webhook) - not a MessageLog::STATUS_* value, since it's an engagement
     * signal layered on top of an already-delivered message, not a delivery-state change.
     */
    public const string TYPE_READ = 'read';

    protected function _construct(): void
    {
        $this->_init(MessageLogEventResource::class);
    }

    public function getMessageLogId(): int
    {
        return (int) $this->getData('message_log_id');
    }

    public function setMessageLogId(int $messageLogId): self
    {
        return $this->setData('message_log_id', $messageLogId);
    }

    public function getEventType(): string
    {
        return (string) $this->getData('event_type');
    }

    public function setEventType(string $eventType): self
    {
        return $this->setData('event_type', $eventType);
    }

    public function getUrl(): ?string
    {
        $url = $this->getData('url');
        return $url === null ? null : (string) $url;
    }

    public function setUrl(?string $url): self
    {
        return $this->setData('url', $url);
    }
}
