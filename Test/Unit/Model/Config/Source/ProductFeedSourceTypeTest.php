<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\ProductFeedSourceType;
use PHPUnit\Framework\TestCase;

class ProductFeedSourceTypeTest extends TestCase
{
    public function testToOptionArrayReturnsBothSourceTypes(): void
    {
        $options = (new ProductFeedSourceType())->toOptionArray();

        self::assertSame(
            [ProductFeedSourceType::CATEGORY, ProductFeedSourceType::RULE],
            array_column($options, 'value')
        );
        self::assertSame('Category', (string) $options[0]['label']);
        self::assertSame('Cart Price Rule', (string) $options[1]['label']);
    }
}
