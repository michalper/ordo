<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Ordo\Automation\Api\LoyaltyPointsManagementInterface;

/**
 * REST's own webapi.xml ACL gate (resource="self") already rejects an unauthenticated request
 * with 401 before this class is ever reached, but GraphQL's @resolver fields have no such
 * built-in gate - see UserContextCustomerIdResolver's own docblock for why
 * requireCustomerId() exists and checks for customer id 0 explicitly.
 */
class LoyaltyPointsManagement implements LoyaltyPointsManagementInterface
{
    public function __construct(
        private readonly LoyaltyPointsManager $loyaltyPointsManager,
        private readonly UserContextInterface $userContext,
        private readonly UserContextCustomerIdResolver $userContextCustomerIdResolver
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
        return $this->userContextCustomerIdResolver->requireCustomerId($this->userContext);
    }
}
