<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

/**
 * Decides whether a given customer belongs to a campaign's deliberate no-send holdout group —
 * closes the ROADMAP.md "no holdout group" gap (docs/adr/0055). Deterministic per
 * (campaign, customer), the same crc32-bucket technique SplitVariantSelector already uses to
 * keep a customer on the same side of a split across repeat dispatches: hashing in a
 * 'holdout:<campaign>:<customer>' string keeps this class's own bucket space independent of
 * SplitVariantSelector's 'campaign:splitAction:identity' one, so the two features can never
 * collide on the same hash for the same customer.
 *
 * Deliberately customer-only (no visitor/email fallback like SplitVariantSelector has) - a
 * holdout group that can't reliably re-identify the same person across requests isn't a holdout
 * group, it's noise; CampaignDispatcher::runCampaign() skips this check entirely when the
 * dispatch context carries no customer_id rather than falling back to a random per-call answer.
 */
class HoldoutSelector
{
    private const int BUCKET_SCALE = 100;

    /**
     * $holdoutPercent is 0-100 (Model\Campaign::getHoldoutPercent() already clamps it) - 0 always
     * returns false (bucket is 0-99, never < 0) and 100 always returns true (bucket is always <
     * 100), matching the "0/null = off" convention without a separate early-return branch here.
     */
    public function isHeldOut(int $campaignId, int $customerId, int $holdoutPercent): bool
    {
        // crc32, not a cryptographic hash - same reasoning as SplitVariantSelector::pickByWeight():
        // this only needs a stable, well-distributed bucket per (campaign, customer), not
        // unpredictability against an adversary.
        $bucket = crc32('holdout:' . $campaignId . ':' . $customerId) % self::BUCKET_SCALE;

        return $bucket < $holdoutPercent;
    }
}
