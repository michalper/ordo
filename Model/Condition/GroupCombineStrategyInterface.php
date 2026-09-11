<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Condition;

/**
 * How GroupWalker combines a list of per-item results (leaf conditions and/or nested groups)
 * into one accumulated result, for one specific "domain" of what an evaluation actually returns.
 * The two implementations today are BooleanGroupCombineStrategy (ConditionGroupEvaluator's
 * per-customer bool, used by CampaignDispatcher/SegmentMatcher) and SetGroupCombineStrategy
 * (SegmentMemberResolver's set-level int[] of matching customer ids). GroupWalker itself is
 * domain-agnostic; a strategy owns every domain-specific decision (what "empty" means, how two
 * results combine, when it's safe to stop early) so a future bugfix to the tree-walk itself
 * (e.g. how a malformed nested group is validated) only needs to change in GroupWalker, not in
 * every domain that walks a group.
 */
interface GroupCombineStrategyInterface
{
    /**
     * The accumulator's starting value before the first item in a list is combined in - the
     * "vacuous" result for the given logic (true for AND/all, false for OR/any, in the boolean
     * domain; an internal "nothing intersected yet" sentinel for AND / an empty set for OR, in
     * the set domain).
     */
    public function identity(bool $matchAny): mixed;

    /**
     * The fixed value a group with no (or no valid) nested conditions resolves to, regardless of
     * $matchAny - every existing implementation fails closed here unconditionally (never
     * "matches everyone"), so this doesn't take a $matchAny parameter.
     */
    public function emptyGroupValue(): mixed;

    /**
     * Folds one more item's result ($next - either a leaf condition's result or a nested group's
     * own fully-walked result) into the running $accumulator.
     */
    public function combine(mixed $accumulator, mixed $next, bool $matchAny): mixed;

    /**
     * Whether GroupWalker can stop processing the remaining items in this list because the
     * outcome is already fixed - e.g. one failed condition already dooms an AND, or the running
     * intersection has already gone empty. Returning false unconditionally (as the set-level
     * strategy's OR case does) simply disables the optimization; it never changes correctness.
     */
    public function isShortCircuit(mixed $accumulator, bool $matchAny): bool;
}
