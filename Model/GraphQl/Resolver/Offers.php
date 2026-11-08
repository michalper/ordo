<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Api\OfferRepositoryInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\OfferDataMapper;

/**
 * GraphQL counterpart of the storefront "My Offers" account page
 * (Block\Frontend\OfferViewModel::getOffers()/canSelfExtend()) - same data, same self-extension
 * eligibility rule (shared with Resolver\ExtendOffer via OfferDataMapper), but identity comes
 * from CustomerIdResolver (the GraphQL customer-token context) rather than OfferViewModel's own
 * Magento\Customer\Model\Session, since a decoupled/PWA storefront carries no cookie-based
 * session (see CustomerIdResolver's own docblock and ADR 0032).
 */
class Offers implements ResolverInterface
{
    public function __construct(
        private readonly OfferRepositoryInterface $offerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CustomerIdResolver $customerIdResolver,
        private readonly OfferDataMapper $offerDataMapper
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array<string, mixed>|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        /** @var BaseContextInterface $context */
        $customerId = $this->customerIdResolver->resolveCustomerId($context);
        if ($customerId === null) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(OfferInterface::CUSTOMER_ID, $customerId)
            ->create();

        return array_map(
            $this->offerDataMapper->map(...),
            $this->offerRepository->getList($searchCriteria)->getItems()
        );
    }
}
