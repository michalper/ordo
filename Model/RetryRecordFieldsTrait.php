<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * The `attempts`/`last_error`/`next_retry_at` trio is identical, field-for-field, across every
 * "persisted retry row" model in this module (MessageSendRetry, PushSendRetry) - a trait rather
 * than a shared base class since these otherwise extend Magento's AbstractModel directly and
 * have no other common behavior worth a base class for. Each consuming class must still declare
 * its own ATTEMPTS/LAST_ERROR/NEXT_RETRY_AT constants (same value, `self::` resolves per-class).
 */
trait RetryRecordFieldsTrait
{
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
}
