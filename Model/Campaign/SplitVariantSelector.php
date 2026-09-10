<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

/**
 * Picks which variant of a `split` campaign action a given dispatch belongs to — deterministic
 * per (campaign, split action, customer/visitor identity), so the same customer always lands in
 * the same variant across repeat dispatches of the same trigger, and a delay_minutes resume
 * elsewhere in the chain never re-rolls a variant already assigned earlier in the same dispatch
 * (see CampaignDispatcher::runSplit()'s own docblock for how the assignment survives a resume).
 *
 * @phpstan-type Variant array{key: string, weight: int|float, actions: array<int|string, mixed>}
 */
class SplitVariantSelector
{
    private const int WEIGHT_SCALE = 100;

    /**
     * @param Variant[] $variants
     * @param array<string, mixed> $context
     * @return Variant
     */
    public function selectVariant(int $campaignId, int $splitActionId, array $variants, array &$context): array
    {
        $assignments = $context['ordo_split_assignments'] ?? [];
        $assignedKey = is_array($assignments) ? ($assignments[$splitActionId] ?? null) : null;

        if ($assignedKey !== null) {
            foreach ($variants as $variant) {
                if ($variant['key'] === $assignedKey) {
                    // Already resolved earlier in this same dispatch/resume chain - reuse it
                    // rather than re-hashing, both for correctness (a resume must land in the
                    // exact same branch it started in) and so this stays a no-op the second time
                    // a customer's already-scheduled resume calls back into the same split node.
                    return $variant;
                }
            }
            // The previously-assigned key no longer exists among $variants (an admin edited the
            // split's variants between the original dispatch and a delayed resume) - fall
            // through and re-roll rather than erroring; this is a rare edit-mid-flight edge case,
            // not something worth failing the whole action chain over.
        }

        $variant = $this->pickByWeight($campaignId, $splitActionId, $variants, $this->resolveIdentity($context));

        if (!is_array($assignments)) {
            $assignments = [];
        }
        $assignments[$splitActionId] = $variant['key'];
        $context['ordo_split_assignments'] = $assignments;

        return $variant;
    }

    /**
     * customer_id (a real, known customer) is preferred since that's the identity a repeat
     * order_placed/customer_registered/... dispatch will carry every time; visitor_id covers an
     * anonymous trigger context (e.g. visitor_tag_added); email is a last resort for any context
     * that carries neither. A dispatch with none of the three (extremely rare - no known
     * customer/visitor/email context at all) falls back to an actually-random pick, which is
     * fine: there is no stable identity to be consistent WITH in that case anyway.
     *
     * @param array<string, mixed> $context
     */
    private function resolveIdentity(array $context): string
    {
        foreach (['customer_id' => 'customer', 'visitor_id' => 'visitor', 'email' => 'email'] as $key => $prefix) {
            $value = $context[$key] ?? null;
            // is_scalar() (not merely isset()) narrows $value to bool|int|float|string for
            // PHPStan and, more importantly, guards against a malformed context accidentally
            // carrying an array/object under one of these keys - concatenating that below would
            // otherwise be a real runtime TypeError, not just a static-analysis complaint.
            if (is_scalar($value) && $value !== '') {
                return $prefix . ':' . $value;
            }
        }

        return 'random:' . random_int(0, PHP_INT_MAX);
    }

    /**
     * @param Variant[] $variants
     * @return Variant
     */
    private function pickByWeight(int $campaignId, int $splitActionId, array $variants, string $identity): array
    {
        $totalWeight = array_sum(array_map(static fn (array $v): float => (float) $v['weight'], $variants));
        if ($totalWeight <= 0) {
            return $variants[0];
        }

        // crc32, not a cryptographic hash - this only needs a stable, well-distributed bucket
        // per identity, not unpredictability against an adversary.
        $bucket = crc32($campaignId . ':' . $splitActionId . ':' . $identity) % self::WEIGHT_SCALE;

        $cumulative = 0.0;
        foreach ($variants as $variant) {
            $cumulative += (float) $variant['weight'] / $totalWeight * self::WEIGHT_SCALE;
            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        // Floating-point rounding at the very top of the bucket range (e.g. bucket=99 against a
        // cumulative that lands at 98.999...) - the last variant is the correct fallback, not an
        // error, since the weights above already summed the full range.
        return $variants[count($variants) - 1];
    }
}
