<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\Condition\OrderTotalAtLeast;
use PHPUnit\Framework\TestCase;

class OrderTotalAtLeastTest extends TestCase
{
    private OrderTotalAtLeast $condition;

    protected function setUp(): void
    {
        $this->condition = new OrderTotalAtLeast();
    }

    public function testSatisfiedWhenOrderTotalMeetsThreshold(): void
    {
        self::assertTrue($this->condition->isSatisfied(['order_total' => 500.0], ['amount' => '500']));
    }

    public function testSatisfiedWhenOrderTotalExceedsThreshold(): void
    {
        self::assertTrue($this->condition->isSatisfied(['order_total' => 750.5], ['amount' => '500']));
    }

    public function testNotSatisfiedWhenOrderTotalIsBelowThreshold(): void
    {
        self::assertFalse($this->condition->isSatisfied(['order_total' => 499.99], ['amount' => '500']));
    }

    public function testNotSatisfiedWhenContextIsMissingOrderTotal(): void
    {
        self::assertFalse($this->condition->isSatisfied([], ['amount' => '500']));
    }
}
