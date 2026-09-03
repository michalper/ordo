<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\ContentBlockType;
use PHPUnit\Framework\TestCase;

class ContentBlockTypeTest extends TestCase
{
    public function testToOptionArrayReturnsAllThreeTypes(): void
    {
        $options = (new ContentBlockType())->toOptionArray();

        self::assertSame(
            [ContentBlockType::SNIPPET, ContentBlockType::RSS, ContentBlockType::PRODUCT_FEED],
            array_column($options, 'value')
        );
        self::assertSame('HTML Snippet', (string) $options[0]['label']);
        self::assertSame('RSS Feed', (string) $options[1]['label']);
        self::assertSame('Product Feed', (string) $options[2]['label']);
    }
}
