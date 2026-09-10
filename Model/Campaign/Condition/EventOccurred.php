<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Event\EventOccurredResolver;

/**
 * Params: {"event_type": "cart_add"|"wishlist_add", "event_key": "24-MB01" (optional - blank
 * means any SKU), "within_days": 14}. Context must include "customer_id" (int) - same historical
 * "did this happen at some point" shape as PurchasedSku, not an event-context condition.
 *
 * event_type isn't restricted to a fixed enum here - Observer\TrackCartAdd/TrackWishlistAdd are
 * the only two producers of ordo_visitor_event rows with a customer_id today, but this condition
 * itself works against whatever event_type string a future producer writes, same as
 * VisitorAggregator's own tagging already does.
 */
class EventOccurred implements ConditionInterface
{
    public function __construct(
        private readonly EventOccurredResolver $eventOccurredResolver
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = $context['customer_id'] ?? null;
        if (!is_numeric($customerId)) {
            return false;
        }

        $eventType = trim((string) ($params['event_type'] ?? ''));
        if ($eventType === '') {
            return false;
        }

        $eventKey = trim((string) ($params['event_key'] ?? ''));
        $withinDays = (int) ($params['within_days'] ?? 0);

        return $this->eventOccurredResolver->hasEventOccurred(
            (int) $customerId,
            $eventType,
            $eventKey !== '' ? $eventKey : null,
            $withinDays
        );
    }
}
