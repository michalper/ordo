<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl;

use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Helper\Config;

/**
 * The OrdoOffer GraphQL shape, shared by Resolver\Offers (listing) and Resolver\ExtendOffer
 * (the single fresh offer a successful extension returns) - both need the exact same
 * fields/can_self_extend rule (Block\Frontend\OfferViewModel::canSelfExtend()'s own logic).
 */
class OfferDataMapper
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @return array{
     *     entity_id: ?int, reference: string, status: string, total: float,
     *     currency_code: string, expires_at: string, can_self_extend: bool,
     *     self_extension_days: int
     * }
     */
    public function map(OfferInterface $offer): array
    {
        $maxExtensions = $this->config->getOfferMaxSelfExtensions();

        return [
            'entity_id' => $offer->getEntityId(),
            'reference' => $offer->getReference(),
            'status' => $offer->getStatus(),
            'total' => $offer->getTotal(),
            'currency_code' => $offer->getCurrencyCode(),
            'expires_at' => $offer->getExpiresAt(),
            'can_self_extend' => $offer->getStatus() === OfferInterface::STATUS_SENT
                && $offer->canSelfExtend($maxExtensions),
            'self_extension_days' => $this->config->getOfferSelfExtensionDays(),
        ];
    }
}
