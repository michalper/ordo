<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Api\Data\FreeGiftOfferTierInterface;
use Ordo\Automation\Model\FreeGiftOfferTier as FreeGiftOfferTierModel;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier as FreeGiftOfferTierResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(FreeGiftOfferTierModel::class, FreeGiftOfferTierResource::class);
    }

    /**
     * Tiers for a single offer, ascending by threshold — needed in this order to walk the
     * cascade and sum gift_slots for every tier the subtotal has reached.
     */
    public function addOfferFilter(int $offerId): self
    {
        $this->addFieldToFilter(FreeGiftOfferTierInterface::OFFER_ID, $offerId);
        $this->setOrder(FreeGiftOfferTierInterface::MIN_SUBTOTAL, self::SORT_ORDER_ASC);
        return $this;
    }

    /**
     * @param int[] $offerIds
     */
    public function addOffersFilter(array $offerIds): self
    {
        $this->addFieldToFilter(FreeGiftOfferTierInterface::OFFER_ID, ['in' => $offerIds]);
        $this->setOrder(FreeGiftOfferTierInterface::MIN_SUBTOTAL, self::SORT_ORDER_ASC);
        return $this;
    }
}
