<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\ScoreRule\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\ScoreRule\Edit\BackButton;
use PHPUnit\Framework\TestCase;

class BackButtonTest extends TestCase
{
    public function testGetButtonDataIncludesBackUrl(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->with('*/*/')->willReturn('https://example.com/admin/ordo/scorerule/');

        $request = $this->createStub(RequestInterface::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getRequest')->willReturn($request);

        $data = (new BackButton($context))->getButtonData();

        self::assertSame('Back', (string) $data['label']);
        self::assertSame(10, $data['sort_order']);
        self::assertStringContainsString('https://example.com/admin/ordo/scorerule/', $data['on_click']);
    }
}
