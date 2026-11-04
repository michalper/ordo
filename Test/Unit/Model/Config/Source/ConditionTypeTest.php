<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Campaign\TypeLabels;
use Ordo\Automation\Model\Config\Source\ConditionType;
use PHPUnit\Framework\TestCase;

class ConditionTypeTest extends TestCase
{
    public function testToOptionArrayGroupsAKnownTypeUnderItsOwnCategory(): void
    {
        $pool = new ConditionPool(['tag' => $this->createStub(\Ordo\Automation\Api\Campaign\ConditionInterface::class)]);
        $source = new ConditionType($pool, new TypeLabels());

        self::assertSame(
            [['value' => [['value' => 'tag', 'label' => 'Has Tag']], 'label' => 'Tags & Behavior']],
            $source->toOptionArray()
        );
    }

    public function testToOptionArrayGroupsAnUnmappedTypeUnderOther(): void
    {
        $pool = new ConditionPool(['has_tag' => $this->createStub(\Ordo\Automation\Api\Campaign\ConditionInterface::class)]);
        $source = new ConditionType($pool, new TypeLabels());

        self::assertSame(
            [['value' => [['value' => 'has_tag', 'label' => 'Has Tag']], 'label' => 'Other']],
            $source->toOptionArray()
        );
    }

    public function testToOptionArrayListsCategoriesInTheDefinedOrder(): void
    {
        $pool = new ConditionPool([
            'in_segment' => $this->createStub(\Ordo\Automation\Api\Campaign\ConditionInterface::class),
            'tag' => $this->createStub(\Ordo\Automation\Api\Campaign\ConditionInterface::class),
        ]);
        $source = new ConditionType($pool, new TypeLabels());

        self::assertSame(
            ['Tags & Behavior', 'Segments'],
            array_column($source->toOptionArray(), 'label')
        );
    }

    public function testToOptionArrayEmptyWhenPoolEmpty(): void
    {
        $source = new ConditionType(new ConditionPool(), new TypeLabels());

        self::assertSame([], $source->toOptionArray());
    }
}
