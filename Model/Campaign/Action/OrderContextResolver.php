<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Throwable;

/**
 * Shared "load the real order a campaign action's own context claims, or null" - both
 * SendGa4Event and SendMetaConversionEvent need the authoritative order (grand_total,
 * order_currency_code) rather than trusting whatever a trigger's own context happened to carry
 * (order_placed's own context has order_total but no currency code at all), and both need to
 * fail closed identically when order_id is missing/unloadable.
 */
class OrderContextResolver
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolveOrder(array $context): ?OrderInterface
    {
        $rawOrderId = $context['order_id'] ?? 0;
        $orderId = is_int($rawOrderId) ? $rawOrderId : 0;
        if ($orderId <= 0) {
            return null;
        }

        try {
            return $this->orderRepository->get($orderId);
        } catch (Throwable) {
            return null;
        }
    }
}
