<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\EventType;
use PHPUnit\Framework\TestCase;

class EventTypeTest extends TestCase
{
    public function testToOptionArrayReturnsCartAddAndWishlistAdd(): void
    {
        $values = array_column((new EventType())->toOptionArray(), 'value');

        self::assertSame(['cart_add', 'wishlist_add'], $values);
    }
}
