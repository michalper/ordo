<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

/**
 * The "detractor" mirror of NpsScoreAtLeast - params: {"threshold": 6}. See
 * AbstractNpsScoreThresholdCondition's own docblock for the shared query/field shape this and
 * NpsScoreAtLeast both reuse.
 */
class NpsScoreAtMost extends AbstractNpsScoreThresholdCondition
{
    protected function scoreSatisfiesThreshold(int $score, int $threshold): bool
    {
        return $score <= $threshold;
    }

    protected function sqlComparisonOperator(): string
    {
        return '<=';
    }

    protected function thresholdFieldLabel(): string
    {
        return (string) __('Maximum NPS score');
    }
}
