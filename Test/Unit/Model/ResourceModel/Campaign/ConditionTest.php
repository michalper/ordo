<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign;

use Ordo\Automation\Model\ResourceModel\Campaign\Condition;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class ConditionTest extends AbstractDbTestCase
{
    public function testConstructSetsMainTableAndIdField(): void
    {
        $resource = new Condition($this->makeDbContext());

        self::assertSame('ordo_campaign_condition', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
