<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\RewardTierSelector;
use PHPUnit\Framework\TestCase;

class RewardTierSelectorTest extends TestCase
{
    private RewardTierSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new RewardTierSelector();
    }

    private function tiers(): array
    {
        return [
            ['label' => 'a', 'weight' => 50, 'coupon_rule_id' => null],
            ['label' => 'b', 'weight' => 50, 'coupon_rule_id' => null],
        ];
    }

    public function testSelectTierDistributesAcrossManyRollsRoughlyByWeight(): void
    {
        $counts = ['a' => 0, 'b' => 0];
        for ($i = 0; $i < 2000; $i++) {
            $tier = $this->selector->selectTier($this->tiers());
            $counts[$tier['label']]++;
        }

        // 50/50 over 2000 real random rolls - binomial std dev is ~22, so 700 is roughly 13
        // standard deviations below the mean of 1000: this would only fail if the selection
        // weren't actually random/weighted correctly, not from ordinary run-to-run noise.
        self::assertGreaterThan(700, $counts['a']);
        self::assertGreaterThan(700, $counts['b']);
        self::assertSame(2000, $counts['a'] + $counts['b']);
    }

    public function testSelectTierNeverMutatesItsInputAndCarriesNoState(): void
    {
        $tiers = $this->tiers();

        $first = $this->selector->selectTier($tiers);
        $second = $this->selector->selectTier($tiers);

        self::assertContains($first['label'], ['a', 'b']);
        self::assertContains($second['label'], ['a', 'b']);
        self::assertSame($tiers, $this->tiers(), 'the input array is untouched');
    }

    public function testSelectTierWithZeroTotalWeightReturnsFirstTier(): void
    {
        $tiers = [
            ['label' => 'a', 'weight' => 0, 'coupon_rule_id' => null],
            ['label' => 'b', 'weight' => 0, 'coupon_rule_id' => null],
        ];

        $tier = $this->selector->selectTier($tiers);

        self::assertSame('a', $tier['label']);
    }

    public function testSelectTierPicksTheOnlyTierWhenJustOneIsGiven(): void
    {
        $tiers = [['label' => 'only', 'weight' => 1, 'coupon_rule_id' => 5]];

        $tier = $this->selector->selectTier($tiers);

        self::assertSame('only', $tier['label']);
        self::assertSame(5, $tier['coupon_rule_id']);
    }

    public function testSelectTierFallsBackToTheLastTierWhenCumulativeWeightNeverReachesTheRoll(): void
    {
        // A non-finite weight makes every cumulative-weight comparison in the loop resolve to
        // false (any comparison against NAN/INF arithmetic does), so the loop runs out without
        // picking a tier - same pathological case SplitVariantSelectorTest exercises for its own
        // last-variant fallback.
        $tiers = [
            ['label' => 'a', 'weight' => INF, 'coupon_rule_id' => null],
            ['label' => 'b', 'weight' => 1, 'coupon_rule_id' => null],
        ];

        $tier = $this->selector->selectTier($tiers);

        self::assertSame('b', $tier['label']);
    }
}
