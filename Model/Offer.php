<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;

class Offer extends AbstractModel implements OfferInterface
{
    protected function _construct(): void
    {
        $this->_init(OfferResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);
        return $id === null ? null : (int) $id;
    }

    public function setEntityId($entityId): self
    {
        $this->setData(self::ENTITY_ID, (int) $entityId);
        return $this;
    }

    public function getCustomerId(): int
    {
        return (int) $this->getData(self::CUSTOMER_ID);
    }

    public function setCustomerId(int $customerId): self
    {
        $this->setData(self::CUSTOMER_ID, $customerId);
        return $this;
    }

    public function getReference(): string
    {
        return (string) $this->getData(self::REFERENCE);
    }

    public function setReference(string $reference): self
    {
        $this->setData(self::REFERENCE, $reference);
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::STATUS, $status);
        return $this;
    }

    public function getTotal(): float
    {
        return (float) $this->getData(self::TOTAL);
    }

    public function setTotal(float $total): self
    {
        $this->setData(self::TOTAL, $total);
        return $this;
    }

    public function getCurrencyCode(): string
    {
        return (string) $this->getData(self::CURRENCY_CODE);
    }

    public function setCurrencyCode(string $currencyCode): self
    {
        $this->setData(self::CURRENCY_CODE, $currencyCode);
        return $this;
    }

    public function getExpiresAt(): string
    {
        return (string) $this->getData(self::EXPIRES_AT);
    }

    public function setExpiresAt(string $expiresAt): self
    {
        $this->setData(self::EXPIRES_AT, $expiresAt);
        return $this;
    }

    public function getExtensionCount(): int
    {
        return (int) $this->getData(self::EXTENSION_COUNT);
    }

    public function setExtensionCount(int $extensionCount): self
    {
        $this->setData(self::EXTENSION_COUNT, $extensionCount);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }

    /**
     * Whether this offer can still self-extend its own expiry — capped to avoid indefinite postponement.
     */
    public function canSelfExtend(int $maxExtensions): bool
    {
        return $this->getExtensionCount() < $maxExtensions;
    }
}
