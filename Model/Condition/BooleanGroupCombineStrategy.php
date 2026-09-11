<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Condition;

/**
 * ConditionGroupEvaluator's per-customer boolean combine rules - AND short-circuits false the
 * moment one condition fails, OR short-circuits true the moment one condition passes; an empty
 * or malformed group always fails closed (false). Matches this class's own long-standing
 * behavior from before the GroupWalker extraction exactly - see GroupWalkerTest/
 * ConditionGroupEvaluatorTest for the regression coverage proving that.
 */
class BooleanGroupCombineStrategy implements GroupCombineStrategyInterface
{
    public function identity(bool $matchAny): bool
    {
        // AND (matchAny=false) starts vacuously true (nothing has failed yet); OR (matchAny=true)
        // starts vacuously false (nothing has passed yet).
        return !$matchAny;
    }

    public function emptyGroupValue(): bool
    {
        return false;
    }

    public function combine(mixed $accumulator, mixed $next, bool $matchAny): bool
    {
        return $matchAny ? ($accumulator || $next) : ($accumulator && $next);
    }

    public function isShortCircuit(mixed $accumulator, bool $matchAny): bool
    {
        return $matchAny ? $accumulator === true : $accumulator === false;
    }
}
