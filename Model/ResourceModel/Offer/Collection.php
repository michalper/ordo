<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Offer;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Model\Offer as OfferModel;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(OfferModel::class, OfferResource::class);
    }

    /**
     * Offers that are still "sent" (awaiting a customer decision) and expire on the given date.
     */
    public function addExpiringOnFilter(string $date): self
    {
        $this->addFieldToFilter(OfferInterface::STATUS, OfferInterface::STATUS_SENT);
        $this->addFieldToFilter(OfferInterface::EXPIRES_AT, ['eq' => $date]);
        return $this;
    }

    /**
     * Offers still "sent" whose expiry date has already passed — candidates to be marked expired.
     */
    public function addPastExpiryFilter(string $today): self
    {
        $this->addFieldToFilter(OfferInterface::STATUS, OfferInterface::STATUS_SENT);
        $this->addFieldToFilter(OfferInterface::EXPIRES_AT, ['lt' => $today]);
        return $this;
    }
}
