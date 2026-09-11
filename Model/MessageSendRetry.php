<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;

/**
 * A send_email/send_sms/send_whatsapp campaign action call whose SendRetrier in-process retries
 * were all exhausted and is now queued for a persisted retry with backoff - see
 * etc/db_schema.xml's ordo_message_send_retry comment and Model\Campaign\MessageSendRetryQueue /
 * Cron\RetryFailedMessageSends for how these are created, claimed and eventually resolved.
 * Internal implementation detail, same "not a first-class thing to author" reasoning as
 * CampaignActionRetry.
 */
class MessageSendRetry extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const ACTION_TYPE = 'action_type';
    public const CONTEXT = 'context';
    public const PARAMS = 'params';
    public const ATTEMPTS = 'attempts';
    public const LAST_ERROR = 'last_error';
    public const NEXT_RETRY_AT = 'next_retry_at';

    protected function _construct(): void
    {
        $this->_init(MessageSendRetryResource::class);
    }

    public function getActionType(): string
    {
        return (string) $this->getData(self::ACTION_TYPE);
    }

    public function setActionType(string $actionType): self
    {
        $this->setData(self::ACTION_TYPE, $actionType);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->decodeJson((string) $this->getData(self::CONTEXT));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): self
    {
        $this->setData(self::CONTEXT, (string) json_encode($context));
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->decodeJson((string) $this->getData(self::PARAMS));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): self
    {
        $this->setData(self::PARAMS, (string) json_encode($params));
        return $this;
    }

    public function getAttempts(): int
    {
        return (int) $this->getData(self::ATTEMPTS);
    }

    public function setAttempts(int $attempts): self
    {
        $this->setData(self::ATTEMPTS, $attempts);
        return $this;
    }

    public function getLastError(): ?string
    {
        $value = $this->getData(self::LAST_ERROR);
        return $value === null ? null : (string) $value;
    }

    public function setLastError(?string $lastError): self
    {
        $this->setData(self::LAST_ERROR, $lastError);
        return $this;
    }

    public function getNextRetryAt(): string
    {
        return (string) $this->getData(self::NEXT_RETRY_AT);
    }

    public function setNextRetryAt(string $nextRetryAt): self
    {
        $this->setData(self::NEXT_RETRY_AT, $nextRetryAt);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
