<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock\Renderer;

use Magento\Framework\Escaper;
use Ordo\Automation\Model\ContentBlock\Renderer\RssItemRenderer;
use PHPUnit\Framework\TestCase;

class RssItemRendererTest extends TestCase
{
    private RssItemRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new RssItemRenderer(new Escaper());
    }

    public function testReturnsEmptyStringForEmptyItemList(): void
    {
        self::assertSame('', $this->renderer->render([]));
    }

    public function testEscapesXssBearingTitleAndDescription(): void
    {
        $html = $this->renderer->render([
            [
                'title' => '<script>alert(1)</script>',
                'link' => 'https://example.com/1',
                'description' => '<img src=x onerror=alert(2)>',
            ],
        ]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
    }

    public function testCapsRenderedItemsToConfiguredItemCount(): void
    {
        $items = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = ['title' => 'Item ' . $i, 'link' => '', 'description' => ''];
        }

        $html = $this->renderer->render($items, 3);

        self::assertSame(3, substr_count($html, '<tr><td'));
        self::assertStringContainsString('Item 1', $html);
        self::assertStringContainsString('Item 2', $html);
        self::assertStringContainsString('Item 3', $html);
        self::assertStringNotContainsString('Item 4', $html);
        self::assertStringNotContainsString('Item 8', $html);
    }

    public function testUsesDefaultItemCountWhenNonPositiveIsGiven(): void
    {
        $items = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = ['title' => 'Item ' . $i, 'link' => '', 'description' => ''];
        }

        $html = $this->renderer->render($items, 0);

        self::assertSame(5, substr_count($html, '<tr><td'));
    }

    public function testRendersLinkAsAnchorWhenLinkPresent(): void
    {
        $html = $this->renderer->render([
            ['title' => 'My title', 'link' => 'https://example.com/article', 'description' => 'Body'],
        ]);

        self::assertStringContainsString('<a href="https://example.com/article"', $html);
        self::assertStringContainsString('My title', $html);
    }

    public function testRendersTitleAsSpanWhenLinkMissing(): void
    {
        $html = $this->renderer->render([
            ['title' => 'My title', 'description' => 'Body'],
        ]);

        self::assertStringNotContainsString('<a href="', $html);
        self::assertStringContainsString('<span style="font-weight:bold;">My title</span>', $html);
    }

    public function testDropsNonHttpLinkSchemeAndFallsBackToPlainTitle(): void
    {
        $html = $this->renderer->render([
            ['title' => 'My title', 'link' => 'javascript:alert(document.cookie)', 'description' => 'Body'],
        ]);

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<a href="', $html);
        self::assertStringContainsString('<span style="font-weight:bold;">My title</span>', $html);
    }
}
