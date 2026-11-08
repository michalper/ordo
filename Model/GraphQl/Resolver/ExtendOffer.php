<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Api\OfferManagementInterface;
use Ordo\Automation\Model\GraphQl\OfferDataMapper;

/**
 * GraphQL counterpart of the storefront "My Offers" page's Extend button
 * (Controller\Offer\Extend) and the REST POST /V1/ordo/offers/:offerId/self-extend route - all
 * three are a thin transport onto the same OfferManagementInterface::selfExtend(), which already
 * owns the ownership check and the self-extension policy (Offer::canSelfExtend()). Identity for
 * that call comes from its own DI-injected UserContextInterface, confirmed (against a live
 * instance, not assumed) to be populated correctly for a GraphQL customer-token request too -
 * see ADR 0032.
 */
class ExtendOffer implements ResolverInterface
{
    public function __construct(
        private readonly OfferManagementInterface $offerManagement,
        private readonly OfferDataMapper $offerDataMapper
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{offerId?: int}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $offerId = isset($args['offerId']) ? (int) $args['offerId'] : 0;

        try {
            $offer = $this->offerManagement->selfExtend($offerId);
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()));
        } catch (LocalizedException $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }

        return ['ok' => true, 'offer' => $this->offerDataMapper->map($offer)];
    }
}
