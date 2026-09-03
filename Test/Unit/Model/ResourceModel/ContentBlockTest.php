<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\ContentBlock;

class ContentBlockTest extends AbstractDbTestCase
{
    public function testInitializesWithContentBlockTableAndEntityIdField(): void
    {
        $resource = new ContentBlock($this->makeDbContext());

        self::assertSame('ordo_content_block', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
