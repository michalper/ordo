<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use PHPUnit\Framework\TestCase;

class ConditionPoolTest extends TestCase
{
    public function testGetReturnsRegisteredCondition(): void
    {
        $condition = $this->createMock(ConditionInterface::class);
        $pool = new ConditionPool(['tag' => $condition]);

        self::assertSame($condition, $pool->get('tag'));
    }

    public function testGetReturnsNullForUnregisteredType(): void
    {
        $pool = new ConditionPool([]);

        self::assertNull($pool->get('does_not_exist'));
    }

    public function testGetAvailableTypesReturnsRegisteredKeys(): void
    {
        $pool = new ConditionPool([
            'tag' => $this->createMock(ConditionInterface::class),
            'order_total_gte' => $this->createMock(ConditionInterface::class),
        ]);

        self::assertSame(['tag', 'order_total_gte'], $pool->getAvailableTypes());
    }
}
