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
 *
 * Caches the single most recently resolved (order_id, order) pair, not a growing
 * order_id-keyed map: this class is a shared DI instance that can outlive any one dispatch,
 * including inside the persistent queue consumer processes this module runs (see AGENTS.md) -
 * an unbounded cache here would leak memory for the consumer's entire uptime, not just one
 * request. A single slot still captures the real win (SendGa4Event and SendMetaConversionEvent
 * both configured on the same order_placed dispatch, resolving the same order_id back to back)
 * without growing at all as the consumer works through unrelated orders afterward.
 */
class OrderContextResolver
{
    private ?int $cachedOrderId = null;
    private ?OrderInterface $cachedOrder = null;

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

        if ($orderId === $this->cachedOrderId) {
            return $this->cachedOrder;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (Throwable) {
            $order = null;
        }

        $this->cachedOrderId = $orderId;
        $this->cachedOrder = $order;

        return $order;
    }
}
