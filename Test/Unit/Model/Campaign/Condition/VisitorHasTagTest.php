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

    public function testSatisfiedWhenVisitorHasAnyOfTheTagsDefaultMatchMode(): void
    {
        $this->visitorTagManager->expects(self::once())->method('hasAnyTag')
            ->with('v1', ['vip'])->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(['visitor_id' => 'v1'], ['tags' => ['vip']]));
    }

    public function testNotSatisfiedWhenVisitorHasNoneOfTheTags(): void
    {
        $this->visitorTagManager->expects(self::once())->method('hasAnyTag')
            ->with('v1', ['vip'])->willReturn(false);

        self::assertFalse($this->condition->isSatisfied(['visitor_id' => 'v1'], ['tags' => ['vip']]));
    }

    public function testAllMatchModeUsesHasAllTags(): void
    {
        $this->visitorTagManager->expects(self::once())->method('hasAllTags')
            ->with('v1', ['vip', 'loyal'])->willReturn(true);
        $this->visitorTagManager->expects(self::never())->method('hasAnyTag');

        self::assertTrue($this->condition->isSatisfied(
            ['visitor_id' => 'v1'],
            ['tags' => ['vip', 'loyal'], 'match_mode' => 'all']
        ));
    }

    public function testNotSatisfiedWhenContextIsMissingVisitorId(): void
    {
        $this->visitorTagManager->expects(self::never())->method('hasAnyTag');

        self::assertFalse($this->condition->isSatisfied([], ['tags' => ['vip']]));
    }

    public function testNotSatisfiedWhenParamsIsMissingTags(): void
    {
        $this->visitorTagManager->expects(self::never())->method('hasAnyTag');

        self::assertFalse($this->condition->isSatisfied(['visitor_id' => 'v1'], []));
    }
}
