<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\AdminActionLog;

class AdminActionLogTest extends AbstractDbTestCase
{
    public function testInitializesWithAdminActionLogTableAndEntityIdField(): void
    {
        $resource = new AdminActionLog($this->makeDbContext());

        self::assertSame('ordo_admin_action_log', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
