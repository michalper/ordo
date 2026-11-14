<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\LoyaltyWalletPassManagementInterface;
use Ordo\Automation\Model\Wallet\GoogleWalletPassLinkBuilder;

/**
 * Same guest-GraphQL/REST authorization shape as Model\LoyaltyPointsManagement - see that
 * class's own docblock for why resolveCustomerId() checks for customer id 0 explicitly
 * (UserContextInterface::getUserId() returns 0, not null, for an unauthenticated request).
 */
class LoyaltyWalletPassManagement implements LoyaltyWalletPassManagementInterface
{
    public function __construct(
        private readonly GoogleWalletPassLinkBuilder $googleWalletPassLinkBuilder,
        private readonly UserContextInterface $userContext
    ) {
    }

    public function getMyGoogleWalletSaveUrl(): string
    {
        return $this->googleWalletPassLinkBuilder->buildSaveUrl($this->resolveCustomerId());
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
