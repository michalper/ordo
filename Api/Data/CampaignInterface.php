<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

interface CampaignInterface
{
    public const ENTITY_ID = 'entity_id';
    public const NAME = 'name';
    public const TRIGGER_EVENT = 'trigger_event';
    public const ENABLED = 'enabled';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public const TRIGGER_ORDER_PLACED = 'order_placed';
    public const TRIGGER_CUSTOMER_REGISTERED = 'customer_registered';
    public const TRIGGER_TAG_ADDED = 'tag_added';
    public const TRIGGER_CART_ABANDONED = 'cart_abandoned';

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
     * @return string
     */
    public function getName(): string;

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

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
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled(bool $enabled): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
