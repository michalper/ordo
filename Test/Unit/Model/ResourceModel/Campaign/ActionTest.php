<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign;

use Ordo\Automation\Model\ResourceModel\Campaign\Action;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class ActionTest extends AbstractDbTestCase
{
    public function testConstructSetsMainTableAndIdField(): void
    {
        $resource = new Action($this->makeDbContext());

        self::assertSame('ordo_campaign_action', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
