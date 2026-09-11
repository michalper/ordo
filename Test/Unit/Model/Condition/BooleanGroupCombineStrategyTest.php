<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Condition;

use Ordo\Automation\Model\Condition\BooleanGroupCombineStrategy;
use PHPUnit\Framework\TestCase;

class BooleanGroupCombineStrategyTest extends TestCase
{
    private BooleanGroupCombineStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new BooleanGroupCombineStrategy();
    }

    public function testIdentityIsTrueForAndAndFalseForOr(): void
    {
        self::assertTrue($this->strategy->identity(false));
        self::assertFalse($this->strategy->identity(true));
    }

    public function testEmptyGroupValueIsAlwaysFalse(): void
    {
        self::assertFalse($this->strategy->emptyGroupValue());
    }

    public function testCombineUnderAndIsLogicalAnd(): void
    {
        self::assertTrue($this->strategy->combine(true, true, false));
        self::assertFalse($this->strategy->combine(true, false, false));
        self::assertFalse($this->strategy->combine(false, true, false));
    }

    public function testCombineUnderOrIsLogicalOr(): void
    {
        self::assertTrue($this->strategy->combine(false, true, true));
        self::assertTrue($this->strategy->combine(true, false, true));
        self::assertFalse($this->strategy->combine(false, false, true));
    }

    public function testShortCircuitsAndOnFirstFalseAndOrOnFirstTrue(): void
    {
        self::assertTrue($this->strategy->isShortCircuit(false, false));
        self::assertFalse($this->strategy->isShortCircuit(true, false));
        self::assertTrue($this->strategy->isShortCircuit(true, true));
        self::assertFalse($this->strategy->isShortCircuit(false, true));
    }
}
