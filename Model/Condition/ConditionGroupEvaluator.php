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
 * (see GroupWalker's own docblock) - so this move was a pure refactor, not a behavior change.
 *
 * The tree-walk itself (list-combine, group-entry, nested-group unwrap/validation) now lives in
 * GroupWalker, shared with Model\Segment\SegmentMemberResolver's own set-level (int[]-returning)
 * walk of the same shape - this class supplies only the two things genuinely specific to a
 * per-customer boolean check: BooleanGroupCombineStrategy (short-circuiting AND/OR over bool)
 * and the leaf resolver below (ConditionPool::get()->isSatisfied()).
 */
class ConditionGroupEvaluator
{
    public function __construct(
        private readonly ConditionPool $conditionPool,
        private readonly LoggerInterface $logger,
        private readonly GroupWalker $groupWalker,
        private readonly BooleanGroupCombineStrategy $combineStrategy
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
        $result = $this->groupWalker->walk(
            $specs,
            $logic,
            function (array $spec) use ($context, $unknownTypeLabel): bool {
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
            },
            $this->combineStrategy
        );

        return (bool) $result;
    }
}
