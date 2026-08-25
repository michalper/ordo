<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\HasTag;
use Ordo\Automation\Model\CustomerTagManager;
use PHPUnit\Framework\TestCase;

class HasTagTest extends TestCase
{
    private CustomerTagManager&\PHPUnit\Framework\MockObject\MockObject $customerTagManager;
    private HasTag $condition;

    protected function setUp(): void
    {
        $this->customerTagManager = $this->createMock(CustomerTagManager::class);
        $this->condition = new HasTag($this->customerTagManager);
    }

    public function testSatisfiedWhenCustomerHasTheTag(): void
    {
        $this->customerTagManager->method('hasTag')->with(42, 'vip')->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['tag' => 'vip']));
    }

    public function testNotSatisfiedWhenCustomerLacksTheTag(): void
    {
        $this->customerTagManager->method('hasTag')->with(42, 'vip')->willReturn(false);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['tag' => 'vip']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->customerTagManager->expects(self::never())->method('hasTag');

        self::assertFalse($this->condition->isSatisfied([], ['tag' => 'vip']));
    }

    public function testNotSatisfiedWhenParamsIsMissingTag(): void
    {
        $this->customerTagManager->expects(self::never())->method('hasTag');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }
}
