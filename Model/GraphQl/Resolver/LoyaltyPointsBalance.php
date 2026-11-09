<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Api\LoyaltyPointsManagementInterface;

/**
 * GraphQL counterpart of GET /V1/ordo/loyalty-points/mine - same
 * LoyaltyPointsManagementInterface::getMyBalance(), a second transport onto it. Identity comes
 * from that service's own DI-injected UserContextInterface, confirmed for this module's other
 * "self" service contracts (Credit Limit, Offer self-extend) to work correctly for a GraphQL
 * customer-token request too - see ADR 0032.
 */
class LoyaltyPointsBalance implements ResolverInterface
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
            return $this->loyaltyPointsManagement->getMyBalance();
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()));
        }
    }
}
