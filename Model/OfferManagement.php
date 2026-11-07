<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\OfferManagementInterface;
use Ordo\Automation\Api\OfferRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;

class OfferManagement implements OfferManagementInterface
{
    public function __construct(
        private readonly OfferRepositoryInterface $offerRepository,
        private readonly Config $config,
        private readonly UserContextInterface $userContext,
        private readonly OfferResource $offerResource
    ) {
    }

    public function selfExtend(int $offerId): OfferInterface
    {
        $offer = $this->offerRepository->getById($offerId);

        $customerId = $this->userContext->getUserId();
        if ($customerId === null || $offer->getCustomerId() !== (int) $customerId) {
            // Same "not found" response for a wrong owner as for a missing offer — avoids
            // leaking that offer #123 exists but belongs to someone else.
            throw new NoSuchEntityException(__('Offer with id "%1" does not exist.', $offerId));
        }

        $maxExtensions = $this->config->getOfferMaxSelfExtensions();
        if (!$offer->canSelfExtend($maxExtensions)) {
            throw new LocalizedException(
                __('This offer has already been extended the maximum of %1 time(s).', $maxExtensions)
            );
        }

        $extensionDays = $this->config->getOfferSelfExtensionDays();
        $newExpiry = date('Y-m-d H:i:s', strtotime($offer->getExpiresAt() . " + {$extensionDays} days"));

        if (!$this->offerResource->claimSelfExtension($offer, $newExpiry, $offer->getExtensionCount())) {
            // Lost a race against another self-extend request for this same offer between the
            // canSelfExtend() check above and this write - re-check against a fresh read rather
            // than assume the loser is automatically "at the max" (it might not be).
            $offer = $this->offerRepository->getById($offerId);
            if (!$offer->canSelfExtend($maxExtensions)) {
                throw new LocalizedException(
                    __('This offer has already been extended the maximum of %1 time(s).', $maxExtensions)
                );
            }

            throw new LocalizedException(
                __('This offer was extended by another request just now. Please try again.')
            );
        }

        return $offer;
    }
}
