<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft;

class EmailTemplateDraftTest extends AbstractDbTestCase
{
    public function testInitializesWithEmailTemplateDraftTableAndEntityIdField(): void
    {
        $resource = new EmailTemplateDraft($this->makeDbContext());

        self::assertSame('ordo_email_template_draft', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
