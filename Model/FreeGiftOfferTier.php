<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\FreeGiftOfferTierInterface;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier as FreeGiftOfferTierResource;

class FreeGiftOfferTier extends AbstractModel implements FreeGiftOfferTierInterface
{
    protected function _construct(): void
    {
        $this->_init(FreeGiftOfferTierResource::class);
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

    public function getMinSubtotal(): float
    {
        return (float) $this->getData(self::MIN_SUBTOTAL);
    }

    public function setMinSubtotal(float $minSubtotal): self
    {
        $this->setData(self::MIN_SUBTOTAL, $minSubtotal);
        return $this;
    }

    public function getGiftSlots(): int
    {
        return (int) $this->getData(self::GIFT_SLOTS);
    }

    public function setGiftSlots(int $giftSlots): self
    {
        $this->setData(self::GIFT_SLOTS, $giftSlots);
        return $this;
    }
}
