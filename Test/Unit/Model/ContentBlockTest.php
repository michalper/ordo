<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ContentBlock;

class ContentBlockTest extends AbstractModelTestCase
{
    private function makeModel(): ContentBlock
    {
        return new ContentBlock($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testConfigArrayRoundTrip(): void
    {
        $block = $this->makeModel();
        $block->setConfigArray(['feed_url' => 'https://example.com/rss', 'item_count' => 5]);

        self::assertSame(
            ['feed_url' => 'https://example.com/rss', 'item_count' => 5],
            $block->getConfigArray()
        );
    }

    public function testGetConfigArrayReturnsEmptyArrayForNullConfig(): void
    {
        $block = $this->makeModel();
        $block->setConfig(null);

        self::assertSame([], $block->getConfigArray());
    }

    public function testGetConfigArrayReturnsEmptyArrayForEmptyStringConfig(): void
    {
        $block = $this->makeModel();
        $block->setConfig('');

        self::assertSame([], $block->getConfigArray());
    }

    public function testGetConfigArrayReturnsEmptyArrayForMalformedJson(): void
    {
        $block = $this->makeModel();
        $block->setConfig('{not valid json');

        self::assertSame([], $block->getConfigArray());
    }

    public function testGetConfigArrayReturnsEmptyArrayWhenJsonDecodesToNonArray(): void
    {
        $block = $this->makeModel();
        $block->setConfig('"just a string"');

        self::assertSame([], $block->getConfigArray());
    }

    public function testSimpleGettersAndSetters(): void
    {
        $block = $this->makeModel();
        $block->setIdentifier('welcome-rss');
        $block->setName('Welcome RSS');
        $block->setType('rss');
        $block->setEnabled(true);

        self::assertSame('welcome-rss', $block->getIdentifier());
        self::assertSame('Welcome RSS', $block->getName());
        self::assertSame('rss', $block->getType());
        self::assertTrue($block->isEnabled());
    }

    public function testGetEntityIdReturnsNullWhenUnset(): void
    {
        $block = $this->makeModel();

        self::assertNull($block->getEntityId());
    }

    public function testGetEntityIdReturnsIntWhenSet(): void
    {
        $block = $this->makeModel();
        $block->setData('entity_id', '7');

        self::assertSame(7, $block->getEntityId());
    }
}
