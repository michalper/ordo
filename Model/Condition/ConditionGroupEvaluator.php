<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Psr\Log\LoggerInterface;

/**
 * Shared AND/OR/nested-group condition-list walker - previously duplicated verbatim (down to
 * comments) between Model\CampaignDispatcher and Model\Segment\SegmentMatcher, flagged in
 * ROADMAP.md as a real drift risk ("a fix to one, e.g. 'empty group fails closed', can silently
 * drift from the other over time"). An audit found no ACTUAL drift between the two before this
 * extraction - both already agreed on every case except one deliberate, documented asymmetry
 * (see $emptyListIsSatisfied below) - so this move is a pure refactor, not a behavior change.
 *
 * A condition row whose type is the reserved 'group' pseudo-type holds its own nested
 * {"logic": "all"|"any", "conditions": [...]} in its params instead of a real ConditionPool
 * condition, arbitrarily deep - reuses the existing condition table/params column as-is (no
 * schema change) rather than a parent/child group table. 'group' is deliberately NOT registered
 * in ConditionPool, so it can't be selected via an admin Type dropdown - a data-model capability
 * ahead of its own UI, reachable today only by writing the row directly (API/DB).
 *
 * A nested group left empty by whoever built it always fails closed (never "matches everyone"),
 * regardless of the caller's own top-level empty-list policy - both CampaignDispatcher and
 * SegmentMatcher already agreed on this before extraction, so it's hard-coded here rather than
 * parameterized.
 */
class ConditionGroupEvaluator
{
    public function __construct(
        private readonly ConditionPool $conditionPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<int, array{type: string, params: array<string, mixed>}> $specs the top-level
     *     condition list to evaluate - the caller is responsible for its own top-level
     *     empty-list policy (CampaignDispatcher fires unconditionally on an empty campaign;
     *     SegmentMatcher fails closed on an empty segment - see each caller's own docblock for
     *     why they deliberately differ) - this method is only ever called with a non-empty list.
     * @param array<string, mixed> $context
     * @param string $unknownTypeLabel used only in the log message when a leaf condition's type
     *     isn't registered in ConditionPool (e.g. "campaign condition" vs "segment condition") -
     *     preserves each caller's original, distinct log wording.
     */
    public function evaluate(array $specs, string $logic, array $context, string $unknownTypeLabel): bool
    {
        return $this->evaluateList($specs, $logic, $context, $unknownTypeLabel);
    }

    /**
     * @param array<int, array{type: string, params: array<string, mixed>}> $specs
     * @param array<string, mixed> $context
     */
    private function evaluateList(array $specs, string $logic, array $context, string $unknownTypeLabel): bool
    {
        $matchAny = $logic === 'any';

        foreach ($specs as $spec) {
            $satisfied = $this->evaluateOne($spec, $context, $unknownTypeLabel);

            if ($matchAny && $satisfied) {
                return true;
            }

            if (!$matchAny && !$satisfied) {
                return false;
            }
        }

        // Loop finished without an early return: under AND every entry passed, under OR none of
        // them did.
        return !$matchAny;
    }

    /**
     * @param array{type: string, params: array<string, mixed>} $spec
     * @param array<string, mixed> $context
     */
    private function evaluateOne(array $spec, array $context, string $unknownTypeLabel): bool
    {
        if ($spec['type'] === 'group') {
            return $this->evaluateGroup($spec['params'], $context, $unknownTypeLabel);
        }

        $condition = $this->conditionPool->get($spec['type']);

        if (!$condition instanceof ConditionInterface) {
            $this->logger->error(sprintf(
                'Ordo_Automation: unknown %s type "%s".',
                $unknownTypeLabel,
                $spec['type']
            ));
            return false;
        }

        return $condition->isSatisfied($context, $spec['params']);
    }

    /**
     * @param array<string, mixed> $groupParams
     * @param array<string, mixed> $context
     */
    private function evaluateGroup(array $groupParams, array $context, string $unknownTypeLabel): bool
    {
        $nestedLogic = ($groupParams['logic'] ?? 'all') === 'any' ? 'any' : 'all';
        $nested = $groupParams['conditions'] ?? null;

        if (!is_array($nested) || $nested === []) {
            // An empty nested group is never "fire/matches unconditionally" - it's a group left
            // empty by whoever built it, so it fails closed regardless of the top-level caller's
            // own empty-list policy.
            return false;
        }

        $specs = [];
        foreach ($nested as $item) {
            if (!is_array($item) || !isset($item['type']) || !is_string($item['type'])) {
                continue;
            }
            $specs[] = ['type' => $item['type'], 'params' => $this->asStringKeyedArray($item['params'] ?? [])];
        }

        if ($specs === []) {
            return false;
        }

        return $this->evaluateList($specs, $nestedLogic, $context, $unknownTypeLabel);
    }

    /**
     * Normalizes a decoded-JSON value into a guaranteed array<string, mixed>, dropping any
     * non-string key a hand-written 'group' params blob could otherwise contain - condition
     * params are conceptually always a key-value map, never a list.
     *
     * @return array<string, mixed>
     */
    private function asStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
