<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;

/**
 * One row per individual outbound message a campaign action dispatched — see
 * etc/db_schema.xml's ordo_message_log comment for why this is channel-generic rather than
 * sms-specific.
 */
class MessageLog extends AbstractModel
{
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNDELIVERED = 'undelivered';
    public const STATUS_OPTED_OUT = 'opted_out';

    protected function _construct(): void
    {
        $this->_init(MessageLogResource::class);
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

    public function getToAddress(): string
    {
        return (string) $this->getData('to_address');
    }

    public function setToAddress(string $toAddress): self
    {
        return $this->setData('to_address', $toAddress);
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

    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    public function setStatus(string $status): self
    {
        return $this->setData('status', $status);
    }

    public function getErrorCode(): ?string
    {
        $code = $this->getData('error_code');
        return $code === null ? null : (string) $code;
    }

    public function setErrorCode(?string $errorCode): self
    {
        return $this->setData('error_code', $errorCode);
    }
}
