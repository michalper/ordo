<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Condition\ConditionGroupEvaluator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Primary unit coverage for the shared AND/OR/nested-group walker extracted out of
 * CampaignDispatcher and SegmentMatcher (see ROADMAP.md's "second, independent implementation"
 * item). CampaignDispatcherTest/SegmentMatcherTest still exercise this transitively through their
 * own real callers - kept as integration-style coverage proving the delegation actually works,
 * not removed, since that's a stronger regression guard for a pure refactor than deleting them.
 */
class ConditionGroupEvaluatorTest extends TestCase
{
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeEvaluator(array $conditions): ConditionGroupEvaluator
    {
        return new ConditionGroupEvaluator(new ConditionPool($conditions), $this->logger);
    }

    private function makeCondition(bool $satisfied): ConditionInterface
    {
        $condition = $this->createStub(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn($satisfied);

        return $condition;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllLogicRequiresEveryConditionSatisfied(): void
    {
        $evaluator = $this->makeEvaluator(['a' => $this->makeCondition(true), 'b' => $this->makeCondition(false)]);

        $specs = [
            ['type' => 'a', 'params' => []],
            ['type' => 'b', 'params' => []],
        ];

        self::assertFalse($evaluator->evaluate($specs, 'all', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllLogicPassesWhenEveryConditionSatisfied(): void
    {
        $evaluator = $this->makeEvaluator(['a' => $this->makeCondition(true), 'b' => $this->makeCondition(true)]);

        $specs = [
            ['type' => 'a', 'params' => []],
            ['type' => 'b', 'params' => []],
        ];

        self::assertTrue($evaluator->evaluate($specs, 'all', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnyLogicPassesWhenOneConditionSatisfied(): void
    {
        $evaluator = $this->makeEvaluator(['a' => $this->makeCondition(false), 'b' => $this->makeCondition(true)]);

        $specs = [
            ['type' => 'a', 'params' => []],
            ['type' => 'b', 'params' => []],
        ];

        self::assertTrue($evaluator->evaluate($specs, 'any', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnyLogicFailsWhenNoConditionSatisfied(): void
    {
        $evaluator = $this->makeEvaluator(['a' => $this->makeCondition(false), 'b' => $this->makeCondition(false)]);

        $specs = [
            ['type' => 'a', 'params' => []],
            ['type' => 'b', 'params' => []],
        ];

        self::assertFalse($evaluator->evaluate($specs, 'any', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupPassesViaItsOwnOrLogic(): void
    {
        $evaluator = $this->makeEvaluator(['tag' => $this->makeCondition(true), 'never' => $this->makeCondition(false)]);

        $specs = [
            ['type' => 'tag', 'params' => []],
            ['type' => 'group', 'params' => [
                'logic' => 'any',
                'conditions' => [
                    ['type' => 'never', 'params' => []],
                    ['type' => 'never', 'params' => []],
                ],
            ]],
        ];

        // Top-level AND with a failing nested group -> false.
        self::assertFalse($evaluator->evaluate($specs, 'all', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupCanFlipTheOutcomeViaItsOwnOr(): void
    {
        $evaluator = $this->makeEvaluator(['tag' => $this->makeCondition(true), 'passes' => $this->makeCondition(true)]);

        $specs = [
            ['type' => 'tag', 'params' => []],
            ['type' => 'group', 'params' => [
                'logic' => 'any',
                'conditions' => [
                    ['type' => 'passes', 'params' => []],
                ],
            ]],
        ];

        self::assertTrue($evaluator->evaluate($specs, 'all', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEmptyNestedGroupFailsClosedRegardlessOfOuterLogic(): void
    {
        $evaluator = $this->makeEvaluator([]);

        $specs = [
            ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => []]],
        ];

        self::assertFalse($evaluator->evaluate($specs, 'any', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMalformedNestedGroupEntriesAreDroppedAndCanCauseFailClosed(): void
    {
        $evaluator = $this->makeEvaluator([]);

        $specs = [
            ['type' => 'group', 'params' => [
                'logic' => 'all',
                'conditions' => [
                    'not-an-array',
                    ['no_type' => true],
                    ['type' => 123],
                ],
            ]],
        ];

        self::assertFalse($evaluator->evaluate($specs, 'all', [], 'test condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNonStringKeyedParamsInAGroupEntrySurviveNormalization(): void
    {
        $condition = $this->createMock(ConditionInterface::class);
        $condition->expects(self::once())->method('isSatisfied')
            ->with([], ['keep' => 'me'])
            ->willReturn(true);
        $evaluator = $this->makeEvaluator(['leaf' => $condition]);

        $specs = [
            ['type' => 'group', 'params' => [
                'logic' => 'all',
                'conditions' => [
                    ['type' => 'leaf', 'params' => ['keep' => 'me', 0 => 'dropped']],
                ],
            ]],
        ];

        self::assertTrue($evaluator->evaluate($specs, 'all', [], 'test condition'));
    }

    public function testUnknownConditionTypeLogsWithTheGivenLabelAndFailsClosed(): void
    {
        $evaluator = $this->makeEvaluator([]);

        $this->logger->expects(self::once())->method('error')
            ->with('Ordo_Automation: unknown widget condition type "mystery".');

        $specs = [['type' => 'mystery', 'params' => []]];

        self::assertFalse($evaluator->evaluate($specs, 'all', [], 'widget condition'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLeafConditionReceivesTheContextAndItsOwnParams(): void
    {
        $condition = $this->createMock(ConditionInterface::class);
        $condition->expects(self::once())->method('isSatisfied')
            ->with(['customer_id' => 42], ['tag' => 'vip'])
            ->willReturn(true);
        $evaluator = $this->makeEvaluator(['has_tag' => $condition]);

        $specs = [['type' => 'has_tag', 'params' => ['tag' => 'vip']]];

        self::assertTrue($evaluator->evaluate($specs, 'all', ['customer_id' => 42], 'test condition'));
    }
}
