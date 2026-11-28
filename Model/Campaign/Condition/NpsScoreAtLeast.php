<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

/**
 * Params: {"threshold": 9} — see AbstractNpsScoreThresholdCondition's own docblock for the
 * shared query/field shape this and NpsScoreAtMost both reuse.
 */
class NpsScoreAtLeast extends AbstractNpsScoreThresholdCondition
{
    protected function scoreSatisfiesThreshold(int $score, int $threshold): bool
    {
        return $score >= $threshold;
    }

    protected function sqlComparisonOperator(): string
    {
        return '>=';
    }

    protected function thresholdFieldLabel(): string
    {
        return (string) __('Minimum NPS score');
    }
}
