<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl;

use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * GraphQL-side counterpart of Model\Track\VisitorIdentityResolver::resolveCustomerId() - a
 * decoupled/PWA storefront authenticates via a customer token, not
 * Magento\Customer\Model\Session, so ContextInterface::getUserId() (populated by Magento's own
 * GraphQL customer-token auth, 0 for a guest) is the correct identity source here, not the
 * session the REST/frontend-controller side reads. Shared across every Ordo GraphQL resolver
 * that needs it, same reasoning that resolver's own shared-helper extraction already gives.
 *
 * Accepts the base ContextInterface every ResolverInterface::resolve() actually receives (its
 * own $context parameter carries no type hint beyond that) rather than the customer-aware
 * subtype directly - Magento's real GraphQL dispatch always passes the subtype in practice, but
 * narrowing it here via instanceof (not a type hint a caller could never satisfy statically)
 * keeps every resolver's own call site honestly typed. Fails closed: an unexpected context type
 * resolves to "no customer" (guest), not an exception - the same posture
 * CampaignDispatcher::ConditionPool takes for an unknown condition type.
 */
class CustomerIdResolver
{
    public function resolveCustomerId(BaseContextInterface $context): ?int
    {
        if (!$context instanceof ContextInterface) {
            return null;
        }

        $userId = $context->getUserId();

        return ($userId !== null && $userId > 0) ? $userId : null;
    }
}
