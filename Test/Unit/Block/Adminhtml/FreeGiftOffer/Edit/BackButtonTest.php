<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\FreeGiftOffer\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\FreeGiftOffer\Edit\BackButton;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class BackButtonTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testGetButtonDataIncludesBackUrl(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnMap([['*/*/', [], 'https://example.com/admin/ordo/freegiftoffer/']]);

        $request = $this->createStub(RequestInterface::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getRequest')->willReturn($request);

        $data = (new BackButton($context))->getButtonData();

        self::assertSame('Back', (string) $data['label']);
        self::assertSame(10, $data['sort_order']);
        self::assertStringContainsString('https://example.com/admin/ordo/freegiftoffer/', $data['on_click']);
    }
}
