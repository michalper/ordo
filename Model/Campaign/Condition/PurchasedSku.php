<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Purchase\PurchasedProductResolver;

/**
 * Params: {"sku": "24-MB01"}. Context must include "customer_id" (int) - this is a historical
 * "ever bought this, at any point" check, not an event-context one, same as HasTag/InSegment
 * (see PurchasedProductResolver's own docblock for why it queries sales_order_item directly
 * instead of reading anything off $context beyond the customer id).
 */
class PurchasedSku implements ConditionInterface, BulkResolvableConditionInterface
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

        $sku = trim((string) ($params['sku'] ?? ''));
        if ($sku === '') {
            return false;
        }

        return $this->purchasedProductResolver->hasPurchasedSku((int) $customerId, $sku);
    }

    public function resolveCustomerIds(array $params): array
    {
        $sku = trim((string) ($params['sku'] ?? ''));

        if ($sku === '') {
            return [];
        }

        return $this->purchasedProductResolver->getCustomerIdsWhoPurchasedSku($sku);
    }
}
