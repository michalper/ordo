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
    public function getCustomerId(): int;

    /**
     * @param int $customerId
     * @return $this
     */
    public function setCustomerId(int $customerId): self;

    /**
     * @return string
     */
    public function getReference(): string;

    /**
     * @param string $reference
     * @return $this
     */
    public function setReference(string $reference): self;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * @return float
     */
    public function getTotal(): float;

    /**
     * @param float $total
     * @return $this
     */
    public function setTotal(float $total): self;

    /**
     * @return string
     */
    public function getCurrencyCode(): string;

    /**
     * @param string $currencyCode
     * @return $this
     */
    public function setCurrencyCode(string $currencyCode): self;

    /**
     * @return string
     */
    public function getExpiresAt(): string;

    /**
     * @param string $expiresAt
     * @return $this
     */
    public function setExpiresAt(string $expiresAt): self;

    /**
     * @return int
     */
    public function getExtensionCount(): int;

    /**
     * @param int $extensionCount
     * @return $this
     */
    public function setExtensionCount(int $extensionCount): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Whether this offer can still self-extend its own expiry, given the currently configured cap.
     *
     * @param int $maxExtensions
     * @return bool
     */
    public function canSelfExtend(int $maxExtensions): bool;
}
