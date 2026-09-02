<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Frontend;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\OfferRepositoryInterface;
use Ordo\Automation\Helper\Config;

/**
 * Feeds the "My Offers" storefront account page: the logged-in customer's own offers, each
 * annotated with whether it is still eligible for the customer to self-extend
 * (Offer::canSelfExtend()), so the template doesn't need to know the extension policy.
 */
class OfferViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly OfferRepositoryInterface $offerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly Config $config
    ) {
    }

    /**
     * @return OfferInterface[]
     */
    public function getOffers(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(OfferInterface::CUSTOMER_ID, $customerId)
            ->create();

        return $this->offerRepository->getList($searchCriteria)->getItems();
    }

    public function canSelfExtend(OfferInterface $offer): bool
    {
        return $offer->getStatus() === OfferInterface::STATUS_SENT
            && $offer->canSelfExtend($this->config->getOfferMaxSelfExtensions());
    }

    public function getMaxSelfExtensions(): int
    {
        return $this->config->getOfferMaxSelfExtensions();
    }

    public function getSelfExtensionDays(): int
    {
        return $this->config->getOfferSelfExtensionDays();
    }
}
