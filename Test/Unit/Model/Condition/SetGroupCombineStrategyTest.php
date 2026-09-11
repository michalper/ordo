<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Condition;

use Ordo\Automation\Model\Condition\SetGroupCombineStrategy;
use PHPUnit\Framework\TestCase;

class SetGroupCombineStrategyTest extends TestCase
{
    private SetGroupCombineStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new SetGroupCombineStrategy();
    }

    public function testIdentityIsNullForAndAndEmptyArrayForOr(): void
    {
        self::assertNull($this->strategy->identity(false));
        self::assertSame([], $this->strategy->identity(true));
    }

    public function testEmptyGroupValueIsAlwaysAnEmptyArray(): void
    {
        self::assertSame([], $this->strategy->emptyGroupValue());
    }

    public function testCombineUnderAndReplacesTheSentinelOnFirstItem(): void
    {
        self::assertSame([1, 2], $this->strategy->combine(null, [1, 2], false));
    }

    public function testCombineUnderAndIntersectsPreservingFirstOccurrenceOrder(): void
    {
        self::assertSame([2, 3], $this->strategy->combine([1, 2, 3], [3, 2], false));
    }

    public function testCombineUnderAndZeroesOutImmediatelyWhenNextIsEmpty(): void
    {
        self::assertSame([], $this->strategy->combine([1, 2], [], false));
    }

    public function testCombineUnderOrUnionsPreservingFirstOccurrenceOrder(): void
    {
        self::assertSame([1, 2, 3], $this->strategy->combine([1, 2], [2, 3], true));
    }

    public function testCombineUnderOrWithNullSentinelTreatsItAsEmpty(): void
    {
        self::assertSame([5], $this->strategy->combine(null, [5], true));
    }

    public function testShortCircuitsOnlyUnderAndOnceTheAccumulatorIsEmpty(): void
    {
        self::assertTrue($this->strategy->isShortCircuit([], false));
        self::assertFalse($this->strategy->isShortCircuit([1], false));
        self::assertFalse($this->strategy->isShortCircuit([], true));
    }
}
