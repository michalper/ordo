<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One trigger event that starts a campaign. A campaign has one or more of these — e.g. both
 * `customer_registered` and `tag_added` can start the exact same conditions/actions chain — so
 * this is its own child entity (same pattern as CampaignConditionInterface/
 * CampaignActionInterface), not a single scalar field on CampaignInterface.
 */
interface CampaignTriggerInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const TRIGGER_EVENT = 'trigger_event';
    public const PARAMS = 'params';

    public const TRIGGER_ORDER_PLACED = 'order_placed';
    public const TRIGGER_CUSTOMER_REGISTERED = 'customer_registered';
    public const TRIGGER_TAG_ADDED = 'tag_added';
    public const TRIGGER_CART_ABANDONED = 'cart_abandoned';
    public const TRIGGER_VISITOR_TAG_ADDED = 'visitor_tag_added';
    public const TRIGGER_SCORE_THRESHOLD_CROSSED = 'score_threshold_crossed';

    /**
     * One-off: fires at most once, at (or shortly after, once
     * Cron\DispatchScheduledCampaignTriggers next scans) the datetime in this trigger's own
     * params (key "scheduled_at", e.g. "2026-11-28 09:00:00") - see
     * Model\Campaign\ScheduledTriggerScanner.
     */
    public const TRIGGER_SCHEDULED_AT = 'scheduled_at';

    /**
     * Repeating: fires every time the current time matches this trigger's own params cron
     * expression (key "cron_expression", e.g. "0 8 * * 1" for every Monday at 08:00) - matched
     * the same way Magento's own cron scheduler matches a job's <schedule>, via
     * Magento\Cron\Model\Schedule::matchCronExpression(). Precision is bounded by how often
     * Cron\DispatchScheduledCampaignTriggers itself runs (every 5 minutes) - a cron_expression
     * whose minute field isn't a multiple of 5 will never match a scan and so will never fire.
     */
    public const TRIGGER_RECURRING_SCHEDULE = 'recurring_schedule';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return int
     */
    public function getCampaignId(): int;

    /**
     * @param int $campaignId
     * @return $this
     */
    public function setCampaignId(int $campaignId): self;

    /**
     * @return string
     */
    public function getTriggerEvent(): string;

    /**
     * @param string $triggerEvent
     * @return $this
     */
    public function setTriggerEvent(string $triggerEvent): self;

    /**
     * Raw JSON string, e.g. {"scheduled_at": "2026-11-28 09:00:00"}. Named *ParamsJson*, not
     * *Params*, for the same reason CampaignConditionInterface::getParamsJson() is - avoids
     * colliding with the model's own getParams(): array helper. Null/unused for every
     * event-based trigger type, which carries no config of its own.
     *
     * @return string
     */
    public function getParamsJson(): string;

    /**
     * @param string $paramsJson
     * @return $this
     */
    public function setParamsJson(string $paramsJson): self;
}
