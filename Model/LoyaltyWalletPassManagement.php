<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Authorization\Model\UserContextInterface;
use Ordo\Automation\Api\LoyaltyWalletPassManagementInterface;
use Ordo\Automation\Model\Wallet\GoogleWalletPassLinkBuilder;

/**
 * Same guest-GraphQL/REST authorization shape as Model\LoyaltyPointsManagement - see
 * UserContextCustomerIdResolver's own docblock for why requireCustomerId() checks for customer
 * id 0 explicitly.
 */
class LoyaltyWalletPassManagement implements LoyaltyWalletPassManagementInterface
{
    public function __construct(
        private readonly GoogleWalletPassLinkBuilder $googleWalletPassLinkBuilder,
        private readonly UserContextInterface $userContext,
        private readonly UserContextCustomerIdResolver $userContextCustomerIdResolver
    ) {
    }

    public function getMyGoogleWalletSaveUrl(): string
    {
        return $this->googleWalletPassLinkBuilder->buildSaveUrl(
            $this->userContextCustomerIdResolver->requireCustomerId($this->userContext)
        );
    }
}
