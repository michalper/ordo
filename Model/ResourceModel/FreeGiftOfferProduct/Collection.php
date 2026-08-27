<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Api\Data\FreeGiftOfferProductInterface;
use Ordo\Automation\Model\FreeGiftOfferProduct as FreeGiftOfferProductModel;
use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct as FreeGiftOfferProductResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(FreeGiftOfferProductModel::class, FreeGiftOfferProductResource::class);
    }

    public function addOfferFilter(int $offerId): self
    {
        $this->addFieldToFilter(FreeGiftOfferProductInterface::OFFER_ID, $offerId);
        return $this;
    }

    /**
     * @param int[] $offerIds
     */
    public function addOffersFilter(array $offerIds): self
    {
        $this->addFieldToFilter(FreeGiftOfferProductInterface::OFFER_ID, ['in' => $offerIds]);
        return $this;
    }
}
