<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Customer360;

/**
 * Plain read-only aggregate of everything else in this module already computes about one
 * customer, individually - closes the "Customer 360 / CDP view" ROADMAP.md candidate. Nothing
 * here is new data: every field is read from the same manager/calculator a campaign
 * condition/trigger already reads at dispatch time (CustomerScoreManager, LoyaltyTierCalculator,
 * RfmCalculator, CustomerTagManager, SurveyPrompt, SegmentMatcher), just gathered into one place
 * for a human to look at instead of cross-referencing five separate screens.
 */
class Customer360Snapshot
{
    /**
     * @param string[] $tags
     * @param string[] $matchingSegmentNames
     */
    public function __construct(
        public readonly int $customerId,
        public readonly string $email,
        public readonly string $name,
        public readonly int $leadScore,
        public readonly string $loyaltyTier,
        public readonly ?int $recencyDays,
        public readonly int $orderFrequency,
        public readonly float $monetaryTotal,
        public readonly ?string $rfmScoreLabel,
        public readonly array $tags,
        public readonly ?int $npsScore,
        public readonly array $matchingSegmentNames,
        public readonly int $orderCount,
        public readonly float $orderTotal
    ) {
    }
}
