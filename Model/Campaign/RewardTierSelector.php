<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

/**
 * Picks one weighted reward tier for a single "spin the wheel"/scratch-card popup delivery -
 * Model\Campaign\Action\ShowPopup's own gamified reward_tiers param. Deliberately NOT built on
 * SplitVariantSelector, despite the superficially identical {weight, ...} shape: that class is
 * sticky on purpose (same customer always lands in the same split variant, re-used rather than
 * re-rolled across repeat dispatches/resumes - see its own docblock), which is exactly the wrong
 * contract for a wheel spin. A visitor who triggers the popup action again later (a fresh
 * dispatch, still subject to ShowPopup's own frequency cap - "again" only happens outside that
 * cap window) must be able to land on a different tier, not be locked into their first spin
 * forever. So this selector has no identity/context parameter and no persisted assignment at
 * all - every call is a fresh, real random roll, and "can't just keep re-spinning" is enforced
 * entirely by ShowPopup's existing cap on queuing a new popup in the first place, not by anything
 * in here.
 *
 * @phpstan-type RewardTier array{label: string, weight: int|float, coupon_rule_id: int|null}
 */
class RewardTierSelector
{
    /**
     * @param RewardTier[] $tiers non-empty, already validated/normalized by the caller
     * @return RewardTier
     */
    public function selectTier(array $tiers): array
    {
        $totalWeight = array_sum(array_map(static fn (array $tier): float => (float) $tier['weight'], $tiers));
        if ($totalWeight <= 0) {
            // Same "nobody configured usable weights" fallback as SplitVariantSelector - the
            // first tier is as good a default as any when there's nothing to weigh.
            return $tiers[0];
        }

        // random_int, not crc32-of-an-identity - this is the one real behavioral difference from
        // SplitVariantSelector, see this class's own docblock. mt_getrandmax() rather than
        // PHP_INT_MAX avoids a float overflow once multiplied by $totalWeight on 32-bit builds.
        $roll = random_int(0, mt_getrandmax()) / mt_getrandmax() * $totalWeight;

        $cumulative = 0.0;
        foreach ($tiers as $tier) {
            $cumulative += (float) $tier['weight'];
            if ($roll < $cumulative) {
                return $tier;
            }
        }

        // Floating-point rounding at the very top of the range (or a non-finite weight making
        // every comparison above resolve false) - the last tier is the correct fallback, same
        // reasoning as SplitVariantSelector::pickByWeight()'s own last-variant fallback.
        return $tiers[count($tiers) - 1];
    }
}
