<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Condition;

/**
 * Shared AND/OR/nested-group condition-list tree-walk - the part of ConditionGroupEvaluator and
 * SegmentMemberResolver that was STILL duplicated between them even after ConditionGroupEvaluator
 * itself already unified CampaignDispatcher/SegmentMatcher's own per-customer boolean walk (see
 * that class's docblock). Both classes independently re-implemented the exact same "AND
 * intersects/short-circuits, OR unions/continues, an empty or malformed nested group always
 * fails closed" tree-walk, differing only in what a single leaf condition actually resolves to
 * (a bool for ConditionGroupEvaluator's per-customer check, an int[] of matching customer ids
 * for SegmentMemberResolver's set-level resolve). That one real difference is captured by
 * GroupCombineStrategyInterface + the caller's own leaf-resolver callback; this class owns only
 * the tree-walk itself (list-combine, group-entry, nested-group unwrap/validation), so a future
 * fix to any of those needs to change in exactly one place instead of two.
 */
class GroupWalker
{
    /**
     * @param array<int, array{type: string, params: array<string, mixed>}> $specs the (already
     *   non-empty, per each caller's own top-level guard) list to combine under $logic
     * @param callable(array{type: string, params: array<string, mixed>}): mixed $leafResolver
     *   called for every non-'group' spec; never called with a 'group' spec, which this class
     *   always intercepts and recurses into itself
     */
    public function walk(array $specs, string $logic, callable $leafResolver, GroupCombineStrategyInterface $strategy): mixed
    {
        $matchAny = $logic === 'any';
        $accumulator = $strategy->identity($matchAny);

        foreach ($specs as $spec) {
            $next = $spec['type'] === 'group'
                ? $this->walkGroup($spec['params'], $leafResolver, $strategy)
                : $leafResolver($spec);

            $accumulator = $strategy->combine($accumulator, $next, $matchAny);

            if ($strategy->isShortCircuit($accumulator, $matchAny)) {
                break;
            }
        }

        return $accumulator;
    }

    /**
     * A row whose type is the reserved 'group' pseudo-type holds its own nested
     * {"logic": "all"|"any", "conditions": [...]} in its params instead of a real leaf condition,
     * arbitrarily deep - reuses the existing condition table/params column as-is (no schema
     * change) rather than a parent/child group table. 'group' is deliberately NOT registered in
     * ConditionPool, so it can't be selected via an admin Type dropdown - a data-model capability
     * ahead of its own UI, reachable today only by writing the row directly (API/DB).
     *
     * A nested group left empty (or containing nothing that parses into a valid spec) always
     * fails closed via $strategy->emptyGroupValue() - never "matches everyone/matches
     * unconditionally" - regardless of the caller's own top-level empty-list policy.
     *
     * @param array<string, mixed> $groupParams
     * @param callable(array{type: string, params: array<string, mixed>}): mixed $leafResolver
     */
    private function walkGroup(array $groupParams, callable $leafResolver, GroupCombineStrategyInterface $strategy): mixed
    {
        $nestedLogic = ($groupParams['logic'] ?? 'all') === 'any' ? 'any' : 'all';
        $nested = $groupParams['conditions'] ?? null;

        if (!is_array($nested) || $nested === []) {
            return $strategy->emptyGroupValue();
        }

        $specs = [];
        foreach ($nested as $item) {
            if (!is_array($item) || !isset($item['type']) || !is_string($item['type'])) {
                continue;
            }
            $specs[] = ['type' => $item['type'], 'params' => $this->asStringKeyedArray($item['params'] ?? [])];
        }

        if ($specs === []) {
            return $strategy->emptyGroupValue();
        }

        return $this->walk($specs, $nestedLogic, $leafResolver, $strategy);
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
