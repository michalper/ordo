<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Segment\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Segment\Edit\BackButton;
use PHPUnit\Framework\TestCase;

class BackButtonTest extends TestCase
{
    public function testGetButtonDataIncludesBackUrl(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->with('*/*/')->willReturn('https://example.com/admin/ordo/segment/');

        $request = $this->createMock(RequestInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getRequest')->willReturn($request);

        $data = (new BackButton($context))->getButtonData();

        self::assertSame('Back', (string) $data['label']);
        self::assertSame(10, $data['sort_order']);
        self::assertStringContainsString('https://example.com/admin/ordo/segment/', $data['on_click']);
    }
}
