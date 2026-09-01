<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\VisitorHasTag;
use Ordo\Automation\Model\VisitorTagManager;
use PHPUnit\Framework\TestCase;

class VisitorHasTagTest extends TestCase
{
    private VisitorTagManager&\PHPUnit\Framework\MockObject\MockObject $visitorTagManager;
    private VisitorHasTag $condition;

    protected function setUp(): void
    {
        $this->visitorTagManager = $this->createMock(VisitorTagManager::class);
        $this->condition = new VisitorHasTag($this->visitorTagManager);
    }

    public function testSatisfiedWhenVisitorHasTheTag(): void
    {
        $this->visitorTagManager->method('hasTag')->with('v1', 'vip')->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(['visitor_id' => 'v1'], ['tag' => 'vip']));
    }

    public function testNotSatisfiedWhenVisitorLacksTheTag(): void
    {
        $this->visitorTagManager->method('hasTag')->with('v1', 'vip')->willReturn(false);

        self::assertFalse($this->condition->isSatisfied(['visitor_id' => 'v1'], ['tag' => 'vip']));
    }

    public function testNotSatisfiedWhenContextIsMissingVisitorId(): void
    {
        $this->visitorTagManager->expects(self::never())->method('hasTag');

        self::assertFalse($this->condition->isSatisfied([], ['tag' => 'vip']));
    }

    public function testNotSatisfiedWhenParamsIsMissingTag(): void
    {
        $this->visitorTagManager->expects(self::never())->method('hasTag');

        self::assertFalse($this->condition->isSatisfied(['visitor_id' => 'v1'], []));
    }
}
