<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\PendingPopup;

class PendingPopupTest extends AbstractDbTestCase
{
    public function testInitializesWithPendingPopupTableAndEntityIdField(): void
    {
        $resource = new PendingPopup($this->makeDbContext());

        self::assertSame('ordo_pending_popup', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
