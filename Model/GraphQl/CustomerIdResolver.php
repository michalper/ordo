<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl;

use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * GraphQL-side counterpart of Model\Track\VisitorIdentityResolver::resolveCustomerId() - a
 * decoupled/PWA storefront authenticates via a customer token, not
 * Magento\Customer\Model\Session, so ContextInterface::getUserId() (populated by Magento's own
 * GraphQL customer-token auth, 0 for a guest) is the correct identity source here, not the
 * session the REST/frontend-controller side reads. Shared across every Ordo GraphQL resolver
 * that needs it, same reasoning that resolver's own shared-helper extraction already gives.
 */
class CustomerIdResolver
{
    public function resolveCustomerId(ContextInterface $context): ?int
    {
        $userId = $context->getUserId();

        return ($userId !== null && $userId > 0) ? $userId : null;
    }
}
