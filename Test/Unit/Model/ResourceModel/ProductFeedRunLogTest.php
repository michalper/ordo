<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\ProductFeedRunLog;

class ProductFeedRunLogTest extends AbstractDbTestCase
{
    public function testInitializesWithProductFeedRunLogTableAndEntityIdField(): void
    {
        $resource = new ProductFeedRunLog($this->makeDbContext());

        self::assertSame('ordo_product_feed_run_log', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
