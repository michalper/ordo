<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\TypeLabels;
use Ordo\Automation\Model\Config\Source\ActionType;
use PHPUnit\Framework\TestCase;

class ActionTypeTest extends TestCase
{
    public function testToOptionArrayReflectsPool(): void
    {
        $pool = new ActionPool(['tag_customer' => $this->createStub(\Ordo\Automation\Api\Campaign\ActionInterface::class)]);
        $source = new ActionType($pool, new TypeLabels());

        self::assertSame(
            [['value' => 'tag_customer', 'label' => 'Tag Customer']],
            $source->toOptionArray()
        );
    }

    public function testToOptionArrayEmptyWhenPoolEmpty(): void
    {
        $source = new ActionType(new ActionPool(), new TypeLabels());

        self::assertSame([], $source->toOptionArray());
    }
}
