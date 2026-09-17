<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\PriceWatch;

use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class PriceWatchSubscriptionTest extends AbstractDbTestCase
{
    public function testInitializesWithPriceWatchSubscriptionTableAndEntityIdField(): void
    {
        $resource = new PriceWatchSubscription($this->makeDbContext());

        self::assertSame('ordo_price_watch_subscription', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
