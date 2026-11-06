<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

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
        self::assertTrue($this->condition->isSatisfied(['order_total' => 100.0], ['amount' => '100']));
    }

    public function testNotSatisfiedWhenOrderTotalIsBelowThreshold(): void
    {
        self::assertFalse($this->condition->isSatisfied(['order_total' => 99.99], ['amount' => '100']));
    }

    public function testNotSatisfiedWhenContextIsMissingOrderTotal(): void
    {
        self::assertFalse($this->condition->isSatisfied([], ['amount' => '100']));
    }

    /**
     * An order_total only ever exists in a single event's dispatch context - there's no standing
     * property of a customer to resolve a segment's "currently matching" set against.
     */
    public function testResolveCustomerIdsAlwaysMatchesNobody(): void
    {
        self::assertSame([], $this->condition->resolveCustomerIds(['amount' => '100']));
    }
}
