<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\PriceWatchType;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use PHPUnit\Framework\TestCase;

class PriceWatchTypeTest extends TestCase
{
    public function testToOptionArrayListsBothWatchTypes(): void
    {
        $options = (new PriceWatchType())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, $values);
        self::assertContains(PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK, $values);
        self::assertCount(2, $options);
    }
}
