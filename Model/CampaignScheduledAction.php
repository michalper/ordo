<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;

/**
 * A paused campaign action chain waiting on a delay_minutes step — see
 * etc/db_schema.xml's ordo_campaign_scheduled_action comment and CampaignDispatcher for how
 * this gets created and resumed. Internal implementation detail of the delay feature, not
 * exposed via its own REST resource (a store doesn't manage these directly — they're an
 * artifact of a campaign that has a delayed action, not a first-class thing to author).
 */
class CampaignScheduledAction extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const RESUME_ACTION_ID = 'resume_action_id';
    public const CONTEXT = 'context';
    public const RUN_AT = 'run_at';
    public const EXECUTED_AT = 'executed_at';

    protected function _construct(): void
    {
        $this->_init(CampaignScheduledActionResource::class);
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

    public function getRunAt(): string
    {
        return (string) $this->getData(self::RUN_AT);
    }

    public function setRunAt(string $runAt): self
    {
        $this->setData(self::RUN_AT, $runAt);
        return $this;
    }

    public function getExecutedAt(): ?string
    {
        $value = $this->getData(self::EXECUTED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setExecutedAt(?string $executedAt): self
    {
        $this->setData(self::EXECUTED_AT, $executedAt);
        return $this;
    }
}
