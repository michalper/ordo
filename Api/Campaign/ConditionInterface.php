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

    /**
     * Which of this type's own params are dedicated, labeled admin-UI fields (as opposed to the
     * raw JSON params textarea every node also has) - the single source of truth
     * Model\Campaign\CampaignSaveProcessor/Model\Segment\SegmentSaveProcessor read to know which
     * posted field values belong to this type (so one row's dedicated field never leaks into
     * another row's params), and Block\Adminhtml\Campaign\Edit\Flow reads to render the Flow
     * canvas's own per-type field inputs. Adding a new condition type's dedicated fields never
     * means touching either of those classes, only this method.
     *
     * @return array<int, array{
     *     name: string, label: string, notice?: string, options?: array<int|string, string>, type?: string
     * }>
     */
    public function getParamFields(): array;
}
