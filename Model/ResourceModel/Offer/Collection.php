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
     * Offers that are still "sent" (awaiting a customer decision) and expire on or before the
     * given date - lteq, not eq, same reasoning as the structurally identical
     * ReorderCycle\Collection::addDueTodayFilter(): a cron tick missed on the offer's exact
     * target date (downtime, a delayed deploy, the feature briefly disabled) would otherwise
     * never match again, since the target date keeps recalculating relative to "now" on every
     * future run and will never again equal this offer's fixed expires_at - permanently skipping
     * its reminder. The reminder log's own claim table still guards against a double-send for an
     * offer this catches up on.
     */
    public function addExpiringOnFilter(string $date): self
    {
        $this->addFieldToFilter(OfferInterface::STATUS, OfferInterface::STATUS_SENT);
        $this->addFieldToFilter(OfferInterface::EXPIRES_AT, ['lteq' => $date]);
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
