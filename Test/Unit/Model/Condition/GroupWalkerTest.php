<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Condition;

use Ordo\Automation\Model\Condition\BooleanGroupCombineStrategy;
use Ordo\Automation\Model\Condition\GroupWalker;
use Ordo\Automation\Model\Condition\SetGroupCombineStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of the shared tree-walk itself, independent of either real caller
 * (ConditionGroupEvaluator/SegmentMemberResolver already exercise it transitively through their
 * own test suites - kept as integration-style regression coverage, not removed).
 */
class GroupWalkerTest extends TestCase
{
    private GroupWalker $walker;

    protected function setUp(): void
    {
        $this->walker = new GroupWalker();
    }

    public function testBooleanAndShortCircuitsAndStopsCallingTheLeafResolver(): void
    {
        $calls = [];
        $leaf = function (array $spec) use (&$calls) {
            $calls[] = $spec['type'];
            return $spec['type'] !== 'fails';
        };

        $result = $this->walker->walk(
            [
                ['type' => 'fails', 'params' => []],
                ['type' => 'never_reached', 'params' => []],
            ],
            'all',
            $leaf,
            new BooleanGroupCombineStrategy()
        );

        self::assertFalse($result);
        self::assertSame(['fails'], $calls);
    }

    public function testBooleanOrShortCircuitsAndStopsCallingTheLeafResolver(): void
    {
        $calls = [];
        $leaf = function (array $spec) use (&$calls) {
            $calls[] = $spec['type'];
            return $spec['type'] === 'passes';
        };

        $result = $this->walker->walk(
            [
                ['type' => 'passes', 'params' => []],
                ['type' => 'never_reached', 'params' => []],
            ],
            'any',
            $leaf,
            new BooleanGroupCombineStrategy()
        );

        self::assertTrue($result);
        self::assertSame(['passes'], $calls);
    }

    public function testBooleanNestedGroupUsesItsOwnLogic(): void
    {
        $leaf = fn (array $spec): bool => $spec['type'] === 'b' || $spec['type'] === 'c';

        // Top-level AND: 'a' (true) AND group{OR: b(true), never-evaluated-because-or-shortcircuits}
        $result = $this->walker->walk(
            [
                ['type' => 'b', 'params' => []],
                [
                    'type' => 'group',
                    'params' => [
                        'logic' => 'any',
                        'conditions' => [
                            ['type' => 'zzz', 'params' => []],
                            ['type' => 'c', 'params' => []],
                        ],
                    ],
                ],
            ],
            'all',
            $leaf,
            new BooleanGroupCombineStrategy()
        );

        self::assertTrue($result);
    }

    public function testBooleanEmptyOrMalformedGroupFailsClosedRegardlessOfTopLevelLogic(): void
    {
        $leaf = fn (): bool => true;

        $emptyGroupSpec = ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => []]];
        $malformedGroupSpec = ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => [['no_type' => true]]]];

        // Under OR, one passing leaf plus a failing (empty) group must still combine to true -
        // proves the empty group itself resolves false, not that it aborts the whole walk.
        self::assertTrue($this->walker->walk(
            [['type' => 'x', 'params' => []], $emptyGroupSpec],
            'any',
            $leaf,
            new BooleanGroupCombineStrategy()
        ));

        // Under AND, the same failing group must fail the whole thing.
        self::assertFalse($this->walker->walk(
            [['type' => 'x', 'params' => []], $malformedGroupSpec],
            'all',
            $leaf,
            new BooleanGroupCombineStrategy()
        ));
    }

    public function testSetAndIntersectsAndShortCircuitsOnFirstEmptyResult(): void
    {
        $calls = [];
        $leaf = function (array $spec) use (&$calls) {
            $calls[] = $spec['type'];
            return $spec['type'] === 'empty' ? [] : [1, 2, 3];
        };

        $result = $this->walker->walk(
            [
                ['type' => 'empty', 'params' => []],
                ['type' => 'never_reached', 'params' => []],
            ],
            'all',
            $leaf,
            new SetGroupCombineStrategy()
        );

        self::assertSame([], $result);
        self::assertSame(['empty'], $calls);
    }

    public function testSetAndIntersectsAcrossMultipleSpecs(): void
    {
        $leaf = fn (array $spec): array => $spec['params']['ids'];

        $result = $this->walker->walk(
            [
                ['type' => 'a', 'params' => ['ids' => [1, 2, 3]]],
                ['type' => 'b', 'params' => ['ids' => [2, 3, 4]]],
            ],
            'all',
            $leaf,
            new SetGroupCombineStrategy()
        );

        self::assertSame([2, 3], $result);
    }

    public function testSetOrUnionsAndDoesNotShortCircuit(): void
    {
        $calls = [];
        $leaf = function (array $spec) use (&$calls) {
            $calls[] = $spec['type'];
            return $spec['params']['ids'];
        };

        $result = $this->walker->walk(
            [
                ['type' => 'a', 'params' => ['ids' => [1, 2]]],
                ['type' => 'b', 'params' => ['ids' => [2, 3]]],
            ],
            'any',
            $leaf,
            new SetGroupCombineStrategy()
        );

        self::assertSame([1, 2, 3], $result);
        self::assertSame(['a', 'b'], $calls);
    }

    public function testSetNestedGroupResolvesWithItsOwnLogic(): void
    {
        $leaf = fn (array $spec): array => $spec['params']['ids'];

        $result = $this->walker->walk(
            [
                ['type' => 'a', 'params' => ['ids' => [1, 2, 3]]],
                [
                    'type' => 'group',
                    'params' => [
                        'logic' => 'any',
                        'conditions' => [
                            ['type' => 'b', 'params' => ['ids' => [3, 4]]],
                            ['type' => 'c', 'params' => ['ids' => [5]]],
                        ],
                    ],
                ],
            ],
            'all',
            $leaf,
            new SetGroupCombineStrategy()
        );

        // Nested group under OR resolves to [3, 4, 5]; intersected with the top-level [1, 2, 3]
        // under AND leaves only 3.
        self::assertSame([3], $result);
    }

    public function testSetEmptyOrMalformedGroupResolvesToNoCustomers(): void
    {
        $leaf = fn (): array => [1, 2];

        $emptyGroupSpec = ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => []]];
        $malformedGroupSpec = ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => [['no_type' => true]]]];

        self::assertSame([1, 2], $this->walker->walk(
            [['type' => 'x', 'params' => []], $emptyGroupSpec],
            'any',
            $leaf,
            new SetGroupCombineStrategy()
        ));

        self::assertSame([], $this->walker->walk(
            [['type' => 'x', 'params' => []], $malformedGroupSpec],
            'all',
            $leaf,
            new SetGroupCombineStrategy()
        ));
    }
}
