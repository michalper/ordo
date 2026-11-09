<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\LoyaltyPointsManagementInterface;

/**
 * REST's own webapi.xml ACL gate (resource="self") already rejects an unauthenticated request
 * with 401 before this class is ever reached, but GraphQL's @resolver fields have no such
 * built-in gate - confirmed live (see docs/adr/0044): UserContextInterface::getUserId() returns
 * 0 for a guest GraphQL request, not null, the same trap Resolver\CreditLimit's own docblock
 * already documents for its own service. resolveCustomerId() checks for 0 explicitly rather than
 * only null, so a guest GraphQL request gets a real authorization error instead of silently
 * reading/redeeming customer id 0's (nonexistent) balance.
 */
class LoyaltyPointsManagement implements LoyaltyPointsManagementInterface
{
    public function __construct(
        private readonly LoyaltyPointsManager $loyaltyPointsManager,
        private readonly UserContextInterface $userContext
    ) {
    }

    public function getMyBalance(): int
    {
        return $this->loyaltyPointsManager->getBalance($this->resolveCustomerId());
    }

    public function selfRedeem(): string
    {
        return $this->loyaltyPointsManager->redeem($this->resolveCustomerId());
    }

    private function resolveCustomerId(): int
    {
        $customerId = $this->userContext->getUserId();
        if ($customerId === null || (int) $customerId === 0) {
            throw new NoSuchEntityException(__('No authenticated customer for this request.'));
        }

        return (int) $customerId;
    }
}
