<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\ConversationMessage;

class ConversationMessageTest extends AbstractDbTestCase
{
    public function testInitializesWithConversationMessageTableAndEntityIdField(): void
    {
        $resource = new ConversationMessage($this->makeDbContext());

        self::assertSame('ordo_conversation_message', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
