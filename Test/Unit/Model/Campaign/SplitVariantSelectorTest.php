<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\SplitVariantSelector;
use PHPUnit\Framework\TestCase;

class SplitVariantSelectorTest extends TestCase
{
    private SplitVariantSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new SplitVariantSelector();
    }

    private function variants(): array
    {
        return [
            ['key' => 'a', 'weight' => 50, 'actions' => []],
            ['key' => 'b', 'weight' => 50, 'actions' => []],
        ];
    }

    public function testSelectVariantIsDeterministicForTheSameCustomerAcrossCalls(): void
    {
        $contextA = ['customer_id' => 42];
        $contextB = ['customer_id' => 42];

        $variantA = $this->selector->selectVariant(5, 10, $this->variants(), $contextA);
        $variantB = $this->selector->selectVariant(5, 10, $this->variants(), $contextB);

        self::assertSame($variantA['key'], $variantB['key']);
    }

    public function testSelectVariantDistributesAcrossManyIdentitiesRoughlyByWeight(): void
    {
        $counts = ['a' => 0, 'b' => 0];
        for ($customerId = 1; $customerId <= 500; $customerId++) {
            $context = ['customer_id' => $customerId];
            $variant = $this->selector->selectVariant(5, 10, $this->variants(), $context);
            $counts[$variant['key']]++;
        }

        // 50/50 weights over 500 identities - not exact (crc32 isn't a perfect distribution),
        // but should be nowhere near all-one-variant if the weight math is right.
        self::assertGreaterThan(150, $counts['a']);
        self::assertGreaterThan(150, $counts['b']);
        self::assertSame(500, $counts['a'] + $counts['b']);
    }

    public function testSelectVariantReusesAnAlreadyPersistedAssignmentInsteadOfReRolling(): void
    {
        // A customer_id that would otherwise hash to variant 'a' at 0/100 weight for 'a' -
        // forcing a re-roll (if it happened) to certainly land on 'b'. The persisted assignment
        // must still win.
        $context = ['customer_id' => 1, 'ordo_split_assignments' => [10 => 'a']];
        $variants = [
            ['key' => 'a', 'weight' => 0, 'actions' => []],
            ['key' => 'b', 'weight' => 100, 'actions' => []],
        ];

        $variant = $this->selector->selectVariant(5, 10, $variants, $context);

        self::assertSame('a', $variant['key']);
    }

    public function testSelectVariantWritesTheChosenKeyBackIntoContext(): void
    {
        $context = ['customer_id' => 42];

        $variant = $this->selector->selectVariant(5, 10, $this->variants(), $context);

        self::assertSame($variant['key'], $context['ordo_split_assignments'][10]);
    }

    public function testSelectVariantPreservesOtherSplitNodesAssignmentsInContext(): void
    {
        $context = ['customer_id' => 42, 'ordo_split_assignments' => [7 => 'x']];

        $this->selector->selectVariant(5, 10, $this->variants(), $context);

        self::assertSame('x', $context['ordo_split_assignments'][7]);
        self::assertArrayHasKey(10, $context['ordo_split_assignments']);
    }

    public function testSelectVariantFallsBackToVisitorIdThenEmailWhenNoCustomerId(): void
    {
        $context = ['visitor_id' => 'v-123'];
        $variant = $this->selector->selectVariant(5, 10, $this->variants(), $context);
        self::assertContains($variant['key'], ['a', 'b']);

        $context = ['email' => 'jane@example.com'];
        $variant = $this->selector->selectVariant(5, 10, $this->variants(), $context);
        self::assertContains($variant['key'], ['a', 'b']);
    }

    public function testSelectVariantWithZeroTotalWeightReturnsFirstVariant(): void
    {
        $context = ['customer_id' => 42];
        $variants = [
            ['key' => 'a', 'weight' => 0, 'actions' => []],
            ['key' => 'b', 'weight' => 0, 'actions' => []],
        ];

        $variant = $this->selector->selectVariant(5, 10, $variants, $context);

        self::assertSame('a', $variant['key']);
    }

    public function testSelectVariantReRollsWhenThePersistedAssignmentNoLongerExists(): void
    {
        $context = ['customer_id' => 42, 'ordo_split_assignments' => [10 => 'removed']];

        $variant = $this->selector->selectVariant(5, 10, $this->variants(), $context);

        self::assertContains($variant['key'], ['a', 'b']);
    }

    public function testSelectVariantResetsAMalformedNonArrayAssignmentsEntry(): void
    {
        // A malformed context (e.g. hand-crafted via a direct API call rather than produced by
        // this module's own dispatcher) where 'ordo_split_assignments' isn't an array at all -
        // must not crash, and must end up a clean array afterward.
        $context = ['customer_id' => 42, 'ordo_split_assignments' => 'not-an-array'];

        $variant = $this->selector->selectVariant(5, 10, $this->variants(), $context);

        self::assertContains($variant['key'], ['a', 'b']);
        self::assertIsArray($context['ordo_split_assignments']);
        self::assertSame($variant['key'], $context['ordo_split_assignments'][10]);
    }

    public function testSelectVariantFallsBackToARandomPickWithNoIdentityInContext(): void
    {
        $context = [];

        $variant = $this->selector->selectVariant(5, 10, $this->variants(), $context);

        self::assertContains($variant['key'], ['a', 'b']);
    }
}
