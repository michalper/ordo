<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Api\LoyaltyPointsManagementInterface;

/**
 * GraphQL counterpart of POST /V1/ordo/loyalty-points/redeem - same
 * LoyaltyPointsManagementInterface::selfRedeem(), a second transport onto it. Same
 * "ok: false for an ordinary business rule, hard error only for a missing customer" shape as
 * Resolver\ExtendOffer.
 */
class RedeemLoyaltyPoints implements ResolverInterface
{
    public function __construct(
        private readonly LoyaltyPointsManagementInterface $loyaltyPointsManagement
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
        try {
            $couponCode = $this->loyaltyPointsManagement->selfRedeem();
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()));
        } catch (LocalizedException $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }

        return ['ok' => true, 'couponCode' => $couponCode];
    }
}
