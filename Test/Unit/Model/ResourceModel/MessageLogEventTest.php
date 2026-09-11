<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\MessageLogEvent;

class MessageLogEventTest extends AbstractDbTestCase
{
    public function testInitializesWithMessageLogEventTableAndEntityIdField(): void
    {
        $resource = new MessageLogEvent($this->makeDbContext());

        self::assertSame('ordo_message_log_event', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
