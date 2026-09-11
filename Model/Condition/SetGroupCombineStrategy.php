<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Condition;

/**
 * SegmentMemberResolver's set-level combine rules - AND intersects (short-circuiting the moment
 * the running intersection becomes empty, since no further intersection can ever un-empty it),
 * OR unions the customer-id lists with no short-circuit (a segment can't stop early under OR: a
 * later condition might still contribute ids none of the earlier ones saw, unlike the boolean OR
 * case where "already true" already is the final answer). An empty or malformed group always
 * resolves to no customers (never "matches everyone"). Matches SegmentMemberResolver's own
 * long-standing behavior from before the GroupWalker extraction exactly - see
 * SegmentMemberResolverTest for the regression coverage proving that (including exact result
 * ordering: union/intersect below preserve first-occurrence order the same way the original
 * array_flip-union / array_intersect implementation did).
 */
class SetGroupCombineStrategy implements GroupCombineStrategyInterface
{
    /**
     * null is AND's own "nothing intersected yet" sentinel, distinct from an actual empty result
     * ([]) - the first real value combined in becomes the whole accumulator instead of being
     * intersected against nothing. Never observable outside this class: GroupWalker only ever
     * calls this strategy on a non-empty spec list (both SegmentMemberResolver's top-level guard
     * and GroupWalker's own nested-group validation already exclude the empty case before
     * walk()/combine() is reached), so identity(false)'s null always gets replaced by a real
     * int[] on the very first combine() call.
     *
     * @return int[]|null
     */
    public function identity(bool $matchAny): ?array
    {
        return $matchAny ? [] : null;
    }

    /**
     * @return int[]
     */
    public function emptyGroupValue(): array
    {
        return [];
    }

    /**
     * @param int[]|null $accumulator
     * @param int[] $next
     * @return int[]
     */
    public function combine(mixed $accumulator, mixed $next, bool $matchAny): array
    {
        if ($matchAny) {
            // $accumulator is never actually null here - identity(true) starts it at [] - but
            // PHPStan can't correlate that with $matchAny across the interface's `mixed` params,
            // so this stays a real, harmless null-coalesce rather than an @var override.
            return array_values(array_unique(array_merge($accumulator ?? [], $next)));
        }

        if ($next === []) {
            // An unresolvable/zero-match condition zeroes out the whole AND immediately - no
            // further intersection can ever bring a customer back in.
            return [];
        }

        return $accumulator === null ? $next : array_values(array_intersect($accumulator, $next));
    }

    public function isShortCircuit(mixed $accumulator, bool $matchAny): bool
    {
        return !$matchAny && $accumulator === [];
    }
}
