<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * REST's own webapi.xml ACL gate (resource="self") already rejects an unauthenticated request
 * with 401 before a service like LoyaltyPointsManagement is ever reached, but GraphQL's
 * @resolver fields have no such built-in gate - confirmed live (see docs/adr/0044):
 * UserContextInterface::getUserId() returns 0 for a guest GraphQL request, not null, so this
 * checks for 0 explicitly rather than only null, giving a real authorization error instead of
 * silently reading/acting on customer id 0's (nonexistent) data.
 *
 * Shared by every "my own ..." *Management service a GraphQL resolver calls straight into with
 * no customer id of its own in the query (LoyaltyPointsManagement, CreditLimitManagement,
 * LoyaltyWalletPassManagement) - each used to carry its own, separately-maintained copy of this
 * exact check (found via a full-repo review).
 */
class UserContextCustomerIdResolver
{
    public function requireCustomerId(UserContextInterface $userContext): int
    {
        $customerId = $userContext->getUserId();
        if ($customerId === null || (int) $customerId === 0) {
            throw new NoSuchEntityException(__('No authenticated customer for this request.'));
        }

        return (int) $customerId;
    }
}
