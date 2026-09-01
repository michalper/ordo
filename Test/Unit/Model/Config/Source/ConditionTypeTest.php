<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Campaign\TypeLabels;
use Ordo\Automation\Model\Config\Source\ConditionType;
use PHPUnit\Framework\TestCase;

class ConditionTypeTest extends TestCase
{
    public function testToOptionArrayReflectsPool(): void
    {
        $pool = new ConditionPool(['has_tag' => $this->createStub(\Ordo\Automation\Api\Campaign\ConditionInterface::class)]);
        $source = new ConditionType($pool, new TypeLabels());

        self::assertSame(
            [['value' => 'has_tag', 'label' => 'Has Tag']],
            $source->toOptionArray()
        );
    }

    public function testToOptionArrayEmptyWhenPoolEmpty(): void
    {
        $source = new ConditionType(new ConditionPool(), new TypeLabels());

        self::assertSame([], $source->toOptionArray());
    }
}
