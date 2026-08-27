<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\FreeGiftOffer;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Api\Data\FreeGiftOfferInterface;
use Ordo\Automation\Model\FreeGiftOffer as FreeGiftOfferModel;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(FreeGiftOfferModel::class, FreeGiftOfferResource::class);
    }

    /**
     * Offers currently active — the eligibility calculation only ever needs enabled offers.
     */
    public function addEnabledFilter(): self
    {
        $this->addFieldToFilter(FreeGiftOfferInterface::ENABLED, 1);
        return $this;
    }
}
