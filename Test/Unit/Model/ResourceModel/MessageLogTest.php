<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\MessageLog;

class MessageLogTest extends AbstractDbTestCase
{
    public function testInitializesWithMessageLogTableAndEntityIdField(): void
    {
        $resource = new MessageLog($this->makeDbContext());

        self::assertSame('ordo_message_log', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
