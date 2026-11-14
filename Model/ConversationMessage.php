<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;

/**
 * One row per SMS/WhatsApp message in a conversation thread — the two-way counterpart to
 * MessageLog, which only ever records a campaign-driven send. An inbound row is written by
 * Model\Conversation\InboundMessageProcessor, the shared collaborator both Controller\Sms\Reply
 * and Controller\WhatsApp\Webhook funnel every inbound reply through. An outbound row (an
 * admin-initiated reply) is written by the separate Model\Conversation\OutboundReplySender —
 * deliberately NOT through InboundMessageProcessor, see that class's own docblock and
 * docs/adr/0059 for why the receive-only funnel stays untouched.
 */
class ConversationMessage extends AbstractModel
{
    public const string DIRECTION_INBOUND = 'inbound';
    public const string DIRECTION_OUTBOUND = 'outbound';

    protected function _construct(): void
    {
        $this->_init(ConversationMessageResource::class);
    }

    public function getChannel(): string
    {
        return (string) $this->getData('channel');
    }

    public function setChannel(string $channel): self
    {
        return $this->setData('channel', $channel);
    }

    public function getCustomerId(): ?int
    {
        $id = $this->getData('customer_id');
        return $id === null ? null : (int) $id;
    }

    public function setCustomerId(?int $customerId): self
    {
        return $this->setData('customer_id', $customerId);
    }

    public function getFromAddress(): string
    {
        return (string) $this->getData('from_address');
    }

    public function setFromAddress(string $fromAddress): self
    {
        return $this->setData('from_address', $fromAddress);
    }

    public function getBody(): string
    {
        return (string) $this->getData('body');
    }

    public function setBody(string $body): self
    {
        return $this->setData('body', $body);
    }

    public function getProviderMessageId(): ?string
    {
        $id = $this->getData('provider_message_id');
        return $id === null ? null : (string) $id;
    }

    public function setProviderMessageId(?string $providerMessageId): self
    {
        return $this->setData('provider_message_id', $providerMessageId);
    }

    public function isStopKeyword(): bool
    {
        return (bool) $this->getData('is_stop_keyword');
    }

    public function setIsStopKeyword(bool $isStopKeyword): self
    {
        return $this->setData('is_stop_keyword', $isStopKeyword);
    }

    /**
     * DIRECTION_INBOUND or DIRECTION_OUTBOUND — defaults to DIRECTION_INBOUND for an unsaved
     * model (same default db_schema.xml gives the column itself), so every existing caller that
     * never set this explicitly (InboundMessageProcessor, every pre-existing test) keeps working
     * unchanged.
     */
    public function getDirection(): string
    {
        $direction = $this->getData('direction');
        return $direction === null || $direction === '' ? self::DIRECTION_INBOUND : (string) $direction;
    }

    public function setDirection(string $direction): self
    {
        return $this->setData('direction', $direction);
    }
}
