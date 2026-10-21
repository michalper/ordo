<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * The attempts/last_error/next_retry_at bookkeeping every persisted retry row model shares
 * (CampaignActionRetry, MessageSendRetry, PushSendRetry - each fulfills this via
 * RetryRecordFieldsTrait). Exists so the shared retry plumbing
 * (Cron\AbstractRetryFailedCron, ResourceModel\ClaimsRetryRowTrait) can type against
 * "a retry row" without caring which of the three deliberately separate tables (ADRs 0003/0004)
 * it lives in.
 */
interface RetryRecordInterface
{
    public function getAttempts(): int;

    public function setAttempts(int $attempts): self;

    public function getLastError(): ?string;

    public function setLastError(?string $lastError): self;

    public function getNextRetryAt(): string;

    public function setNextRetryAt(string $nextRetryAt): self;
}
