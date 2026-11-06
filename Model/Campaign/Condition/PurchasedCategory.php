<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Purchase\PurchasedProductResolver;

/**
 * Params: {"category_id": "15"}. Context must include "customer_id" (int). Matches a purchase of
 * ANY product assigned to this category or to any of its subcategories - see
 * PurchasedProductResolver::hasPurchasedInCategory()'s own docblock for how the subtree is
 * resolved. Same historical "ever bought", not event-context, shape as PurchasedSku.
 */
class PurchasedCategory implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly PurchasedProductResolver $purchasedProductResolver
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = $context['customer_id'] ?? null;
        if (!is_numeric($customerId)) {
            return false;
        }

        $categoryId = $params['category_id'] ?? null;
        if (!is_numeric($categoryId) || (int) $categoryId <= 0) {
            return false;
        }

        return $this->purchasedProductResolver->hasPurchasedInCategory((int) $customerId, (int) $categoryId);
    }

    public function resolveCustomerIds(array $params): array
    {
        $categoryId = $params['category_id'] ?? null;

        if (!is_numeric($categoryId) || (int) $categoryId <= 0) {
            return [];
        }

        return $this->purchasedProductResolver->getCustomerIdsWhoPurchasedInCategory((int) $categoryId);
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'category_id', 'label' => (string) __('Category ID')],
        ];
    }
}
