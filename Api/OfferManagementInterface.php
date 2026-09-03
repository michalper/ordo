<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OfferInterface;

/**
 * Customer-facing self-service actions on an offer — kept separate from
 * OfferRepositoryInterface (admin/system CRUD) because this one is scoped to the
 * authenticated customer's own offers and enforces the self-extension policy
 * (Offer::canSelfExtend()) rather than allowing an arbitrary field update.
 */
interface OfferManagementInterface
{
    /**
     * Pushes an offer's expiry back by Helper\Config::getOfferSelfExtensionDays(), capped at
     * Helper\Config::getOfferMaxSelfExtensions() total extensions.
     *
     * @throws NoSuchEntityException if the offer doesn't exist or doesn't belong to the caller
     * @throws LocalizedException if the offer has already used up its self-extensions
     * @throws CouldNotSaveException
     */
    public function selfExtend(int $offerId): OfferInterface;
}
