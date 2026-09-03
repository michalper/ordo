<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\ContentBlock\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\ContentBlock\Edit\RefreshRssButton;
use Ordo\Automation\Model\ContentBlock;
use PHPUnit\Framework\TestCase;

class RefreshRssButtonTest extends TestCase
{
    public function testGetButtonDataReturnsEmptyWhenNoEntityId(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('entity_id')->willReturn(null);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('registry');

        self::assertSame([], (new RefreshRssButton($context, $registry))->getButtonData());
    }

    public function testGetButtonDataReturnsEmptyWhenNoBlockRegistered(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('entity_id')->willReturn(5);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('registry')->with('ordo_content_block')->willReturn(null);

        self::assertSame([], (new RefreshRssButton($context, $registry))->getButtonData());
    }

    public function testGetButtonDataReturnsEmptyWhenTypeIsNotRss(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('entity_id')->willReturn(5);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $block = $this->createStub(ContentBlock::class);
        $block->method('getType')->willReturn('snippet');

        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->with('ordo_content_block')->willReturn($block);

        self::assertSame([], (new RefreshRssButton($context, $registry))->getButtonData());
    }

    public function testGetButtonDataReturnsButtonWhenRssBlockRegistered(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('entity_id')->willReturn(5);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->with('ordo/contentblock/refreshRss')
            ->willReturn('https://example.com/admin/ordo/contentblock/refreshRss/');

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $block = $this->createStub(ContentBlock::class);
        $block->method('getType')->willReturn('rss');

        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->with('ordo_content_block')->willReturn($block);

        $data = (new RefreshRssButton($context, $registry))->getButtonData();

        self::assertSame('Refresh Feed Now', (string) $data['label']);
        self::assertSame('action-secondary', $data['class']);
        self::assertSame(30, $data['sort_order']);
        self::assertStringContainsString(
            'https://example.com/admin/ordo/contentblock/refreshRss/',
            $data['on_click']
        );
        self::assertStringContainsString('content_block_id: 5', $data['on_click']);
        self::assertStringContainsString('form_key: window.FORM_KEY', $data['on_click']);
    }
}
