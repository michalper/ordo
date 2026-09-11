<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;

/**
 * A resumeScheduledAction() call that failed and is now queued for a retry with backoff - see
 * etc/db_schema.xml's ordo_campaign_action_retry comment and Model\Campaign\ActionRetryQueue /
 * Cron\RetryFailedCampaignActions for how these are created, claimed and eventually resolved.
 * Internal implementation detail, same "not a first-class thing to author" reasoning as
 * CampaignScheduledAction.
 */
class CampaignActionRetry extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const RESUME_ACTION_ID = 'resume_action_id';
    public const CONTEXT = 'context';
    public const ATTEMPTS = 'attempts';
    public const LAST_ERROR = 'last_error';
    public const NEXT_RETRY_AT = 'next_retry_at';

    protected function _construct(): void
    {
        $this->_init(CampaignActionRetryResource::class);
    }

    public function getCampaignId(): int
    {
        return (int) $this->getData(self::CAMPAIGN_ID);
    }

    public function setCampaignId(int $campaignId): self
    {
        $this->setData(self::CAMPAIGN_ID, $campaignId);
        return $this;
    }

    public function getResumeActionId(): int
    {
        return (int) $this->getData(self::RESUME_ACTION_ID);
    }

    public function setResumeActionId(int $resumeActionId): self
    {
        $this->setData(self::RESUME_ACTION_ID, $resumeActionId);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        $raw = (string) $this->getData(self::CONTEXT);
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): self
    {
        $this->setData(self::CONTEXT, (string) json_encode($context));
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
}
