<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * Shared shape of a named, on/off, timestamped top-level entity — CampaignInterface and
 * FreeGiftOfferInterface were byte-for-byte identical apart from their own name, so this holds
 * the one copy of that shape; each keeps its own distinct interface (for type-hinting/DI) but
 * extends this instead of redeclaring it.
 */
interface NamedToggleableEntityInterface
{
    public const ENTITY_ID = 'entity_id';
    public const NAME = 'name';
    public const ENABLED = 'enabled';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
