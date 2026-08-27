<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\FreeGiftOfferInterface;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;

class FreeGiftOffer extends AbstractModel implements FreeGiftOfferInterface
{
    protected function _construct(): void
    {
        $this->_init(FreeGiftOfferResource::class);
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

    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    public function setName(string $name): self
    {
        $this->setData(self::NAME, $name);
        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData(self::ENABLED);
    }

    public function setEnabled(bool $enabled): self
    {
        $this->setData(self::ENABLED, $enabled);
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
}
