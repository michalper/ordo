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

    public const TRIGGER_ORDER_PLACED = 'order_placed';
    public const TRIGGER_CUSTOMER_REGISTERED = 'customer_registered';
    public const TRIGGER_TAG_ADDED = 'tag_added';
    public const TRIGGER_CART_ABANDONED = 'cart_abandoned';
    public const TRIGGER_VISITOR_TAG_ADDED = 'visitor_tag_added';

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
}
