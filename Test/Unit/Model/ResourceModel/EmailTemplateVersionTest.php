<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion;

class EmailTemplateVersionTest extends AbstractDbTestCase
{
    public function testInitializesWithEmailTemplateVersionTableAndEntityIdField(): void
    {
        $resource = new EmailTemplateVersion($this->makeDbContext());

        self::assertSame('ordo_email_template_version', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
