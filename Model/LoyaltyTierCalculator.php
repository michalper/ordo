<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Helper\Config;

/**
 * Maps a customer's existing `ordo_customer_score` total (see CustomerScoreManager - the same
 * running lead-score total the score_threshold_crossed trigger already reads) into a named
 * loyalty tier — pure derivation, no new score/points system and no new table: a loyalty
 * program on top of lead scoring, not a separate one. Bronze is the implicit floor (any score
 * below the Silver threshold); Silver/Gold each have their own configurable threshold
 * (Helper\Config::getLoyaltySilverThreshold()/getLoyaltyGoldThreshold()).
 */
class LoyaltyTierCalculator
{
    public const string BRONZE = 'bronze';
    public const string SILVER = 'silver';
    public const string GOLD = 'gold';

    /**
     * Ordered lowest to highest — index in this list IS the tier's rank, used by
     * getTierRank()/isAtLeast() so "gold >= silver >= bronze" doesn't need its own separate
     * comparison table.
     */
    private const array TIER_ORDER = [self::BRONZE, self::SILVER, self::GOLD];

    public function __construct(
        private readonly Config $config,
        private readonly CustomerScoreManager $customerScoreManager
    ) {
    }

    public function getTierForScore(int $score): string
    {
        if ($score >= $this->config->getLoyaltyGoldThreshold()) {
            return self::GOLD;
        }

        if ($score >= $this->config->getLoyaltySilverThreshold()) {
            return self::SILVER;
        }

        return self::BRONZE;
    }

    /**
     * @return int Rank in TIER_ORDER, or -1 for a tier this class doesn't recognize (fails
     *   closed - see isAtLeast()'s own doc for why an unrecognized value must never satisfy).
     */
    public function getTierRank(string $tier): int
    {
        $rank = array_search($tier, self::TIER_ORDER, true);
        return $rank === false ? -1 : $rank;
    }

    /**
     * Whether $score's own tier ranks at or above $minimumTier. An unrecognized $minimumTier
     * (typo in a condition's params, or a tier this store no longer configures) has rank -1,
     * which no real tier's rank (0-2) can ever be >=, so this fails closed rather than
     * accidentally matching everyone.
     */
    public function isAtLeast(int $score, string $minimumTier): bool
    {
        $minimumRank = $this->getTierRank($minimumTier);
        if ($minimumRank < 0) {
            return false;
        }

        return $this->getTierRank($this->getTierForScore($score)) >= $minimumRank;
    }

    /**
     * The lowest score that lands in $tier, or null for the Bronze floor (every score — and
     * every unscored customer — is at least Bronze). -1-rank (unrecognized) tiers have no
     * minimum either, but callers should rank-check first via getTierRank(); this returns
     * null for them too, so a caller that skips that check fails open to Bronze semantics
     * rather than crashing.
     */
    public function getMinimumScoreForTier(string $tier): ?int
    {
        return match ($tier) {
            self::GOLD => $this->config->getLoyaltyGoldThreshold(),
            self::SILVER => $this->config->getLoyaltySilverThreshold(),
            default => null,
        };
    }

    /**
     * @return string[] bronze, silver, gold - in ascending order, for anything that needs to
     *   enumerate every tier (the admin dropdown source, the dashboard stat).
     */
    public function getAllTiers(): array
    {
        return self::TIER_ORDER;
    }

    public function getTierLabel(string $tier): string
    {
        return match ($tier) {
            self::BRONZE => 'Bronze',
            self::SILVER => 'Silver',
            self::GOLD => 'Gold',
            default => ucfirst($tier),
        };
    }

    /**
     * How many customers currently sit in each tier — for the dashboard stat. Derived from two
     * threshold counts plus the total customer count, not three separate range queries: Gold is
     * "score >= gold threshold"; Silver is "at or above the silver threshold, but not already
     * counted as Gold" (silverOrAbove - gold); Bronze is everyone else, INCLUDING a customer
     * with no ordo_customer_score row at all (implicit score 0, always Bronze unless the store
     * configures a silver threshold of 0 or less).
     *
     * @return array{bronze: int, silver: int, gold: int}
     */
    public function getTierDistribution(): array
    {
        $goldCount = $this->customerScoreManager->countCustomersWithScoreAtLeast(
            $this->config->getLoyaltyGoldThreshold()
        );
        $silverOrAboveCount = $this->customerScoreManager->countCustomersWithScoreAtLeast(
            $this->config->getLoyaltySilverThreshold()
        );
        $totalCount = $this->customerScoreManager->countAllCustomers();

        return [
            self::BRONZE => $totalCount - $silverOrAboveCount,
            self::SILVER => $silverOrAboveCount - $goldCount,
            self::GOLD => $goldCount,
        ];
    }
}
