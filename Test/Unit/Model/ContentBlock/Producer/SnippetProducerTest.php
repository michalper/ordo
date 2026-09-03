<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock\Producer;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\Producer\SnippetProducer;
use PHPUnit\Framework\TestCase;

class SnippetProducerTest extends TestCase
{
    private SnippetProducer $producer;

    protected function setUp(): void
    {
        $this->producer = new SnippetProducer();
    }

    private function makeBlock(array $config): ContentBlock
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $block->setConfigArray($config);

        return $block;
    }

    public function testRendersRawHtmlFromConfig(): void
    {
        $block = $this->makeBlock(['html' => '<p>Hello <b>world</b></p>']);

        self::assertSame('<p>Hello <b>world</b></p>', $this->producer->render($block));
    }

    public function testReturnsEmptyStringWhenHtmlKeyIsMissing(): void
    {
        $block = $this->makeBlock(['some_other_key' => 'value']);

        self::assertSame('', $this->producer->render($block));
    }

    public function testReturnsEmptyStringWhenConfigIsEmpty(): void
    {
        $block = $this->makeBlock([]);

        self::assertSame('', $this->producer->render($block));
    }

    public function testReturnsEmptyStringWhenConfigIsMalformedJson(): void
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $block->setConfig('{not valid json');

        self::assertSame('', $this->producer->render($block));
    }
}
