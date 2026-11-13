<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\HoldoutSelector;
use PHPUnit\Framework\TestCase;

class HoldoutSelectorTest extends TestCase
{
    public function testZeroPercentNeverHoldsOut(): void
    {
        $selector = new HoldoutSelector();

        for ($customerId = 1; $customerId <= 50; $customerId++) {
            self::assertFalse($selector->isHeldOut(1, $customerId, 0));
        }
    }

    public function testOneHundredPercentAlwaysHoldsOut(): void
    {
        $selector = new HoldoutSelector();

        for ($customerId = 1; $customerId <= 50; $customerId++) {
            self::assertTrue($selector->isHeldOut(1, $customerId, 100));
        }
    }

    /**
     * Same (campaign, customer) pair must land on the same side of the holdout line every single
     * time - a customer whose dispatch gets re-rolled randomly on every evaluation isn't a real
     * holdout group at all, it's noise that would make the organic-conversion-rate comparison
     * this feature exists for meaningless.
     */
    public function testSameCampaignAndCustomerAlwaysProducesTheSameAnswer(): void
    {
        $selector = new HoldoutSelector();

        for ($campaignId = 1; $campaignId <= 5; $campaignId++) {
            for ($customerId = 1; $customerId <= 20; $customerId++) {
                $first = $selector->isHeldOut($campaignId, $customerId, 30);
                $second = $selector->isHeldOut($campaignId, $customerId, 30);
                self::assertSame($first, $second, "campaign $campaignId / customer $customerId disagreed across calls");
            }
        }
    }

    /**
     * A real distribution check, not just "doesn't crash" - over a reasonably large population,
     * a 20% holdout should land somewhere plausible around 20%, not systematically skew toward 0
     * or 100 (which would indicate the bucket formula is broken, e.g. always landing in the same
     * narrow range).
     */
    public function testHoldoutPercentRoughlyMatchesTheActualFractionHeldOutOverManyCustomers(): void
    {
        $selector = new HoldoutSelector();

        $heldOut = 0;
        $total = 2000;
        for ($customerId = 1; $customerId <= $total; $customerId++) {
            if ($selector->isHeldOut(42, $customerId, 20)) {
                $heldOut++;
            }
        }

        $actualPercent = $heldOut / $total * 100;
        self::assertGreaterThan(15.0, $actualPercent);
        self::assertLessThan(25.0, $actualPercent);
    }

    /**
     * Different customers under the same campaign/percent must not all land on the same side -
     * the hash has to actually distribute them, not collapse to one constant bucket.
     */
    public function testDifferentCustomersCanLandOnDifferentSidesOfTheSameThreshold(): void
    {
        $selector = new HoldoutSelector();

        $results = [];
        for ($customerId = 1; $customerId <= 50; $customerId++) {
            $results[] = $selector->isHeldOut(1, $customerId, 50);
        }

        self::assertContains(true, $results);
        self::assertContains(false, $results);
    }
}
