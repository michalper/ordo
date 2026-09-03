<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

interface OfferInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const REFERENCE = 'reference';
    public const STATUS = 'status';
    public const TOTAL = 'total';
    public const CURRENCY_CODE = 'currency_code';
    public const EXPIRES_AT = 'expires_at';
    public const EXTENSION_COUNT = 'extension_count';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getCustomerId(): int;

    public function setCustomerId(int $customerId): self;

    public function getReference(): string;

    public function setReference(string $reference): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getTotal(): float;

    public function setTotal(float $total): self;

    public function getCurrencyCode(): string;

    public function setCurrencyCode(string $currencyCode): self;

    public function getExpiresAt(): string;

    public function setExpiresAt(string $expiresAt): self;

    public function getExtensionCount(): int;

    public function setExtensionCount(int $extensionCount): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;

    /**
     * Whether this offer can still self-extend its own expiry, given the currently configured cap.
     */
    public function canSelfExtend(int $maxExtensions): bool;
}
