<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Config\Source\ActionType;
use PHPUnit\Framework\TestCase;

class ActionTypeTest extends TestCase
{
    public function testToOptionArrayReflectsPool(): void
    {
        $pool = new ActionPool(['tag_customer' => $this->createMock(\Ordo\Automation\Api\Campaign\ActionInterface::class)]);
        $source = new ActionType($pool);

        self::assertSame(
            [['value' => 'tag_customer', 'label' => 'tag_customer']],
            $source->toOptionArray()
        );
    }

    public function testToOptionArrayEmptyWhenPoolEmpty(): void
    {
        $source = new ActionType(new ActionPool());

        self::assertSame([], $source->toOptionArray());
    }
}
