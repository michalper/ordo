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

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getTriggerEvent(): string;

    public function setTriggerEvent(string $triggerEvent): self;

    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
