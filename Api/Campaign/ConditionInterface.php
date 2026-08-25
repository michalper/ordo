<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Campaign;

/**
 * One evaluable condition type (e.g. "customer has tag X", "order total >= Y"). Registered by
 * string key in di.xml (Model\Campaign\ConditionPool) — adding a new condition type never means
 * touching the dispatcher, only adding a new class and one di.xml line.
 */
interface ConditionInterface
{
    /**
     * @param array<string, mixed> $context event-specific data (customer_id, order, tag, ...)
     * @param array<string, mixed> $params  condition configuration saved on the campaign
     */
    public function isSatisfied(array $context, array $params): bool;
}
