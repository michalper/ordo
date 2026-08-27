<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\FreeGiftOfferProductInterface;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct as FreeGiftOfferProductResource;

class FreeGiftOfferProduct extends AbstractModel implements FreeGiftOfferProductInterface
{
    protected function _construct(): void
    {
        $this->_init(FreeGiftOfferProductResource::class);
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

    public function getOfferId(): int
    {
        return (int) $this->getData(self::OFFER_ID);
    }

    public function setOfferId(int $offerId): self
    {
        $this->setData(self::OFFER_ID, $offerId);
        return $this;
    }

    public function getSku(): string
    {
        return (string) $this->getData(self::SKU);
    }

    public function setSku(string $sku): self
    {
        $this->setData(self::SKU, $sku);
        return $this;
    }
}
