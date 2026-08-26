<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use PHPUnit\Framework\TestCase;

class ActionPoolTest extends TestCase
{
    public function testGetReturnsRegisteredAction(): void
    {
        $action = $this->createMock(ActionInterface::class);
        $pool = new ActionPool(['tag_customer' => $action]);

        self::assertSame($action, $pool->get('tag_customer'));
    }

    public function testGetReturnsNullForUnknownType(): void
    {
        self::assertNull((new ActionPool())->get('missing'));
    }
}
