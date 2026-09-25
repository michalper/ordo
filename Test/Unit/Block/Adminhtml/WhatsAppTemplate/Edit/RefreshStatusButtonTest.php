<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\WhatsAppTemplate\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\WhatsAppTemplate\Edit\RefreshStatusButton;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class RefreshStatusButtonTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testGetButtonDataReturnsEmptyWhenNoEntityId(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['entity_id', null]]);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        self::assertSame([], (new RefreshStatusButton($context))->getButtonData());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetButtonDataIncludesRefreshUrlWhenEntityIdPresent(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')
            ->willReturnMap([[
                '*/*/refreshstatus',
                ['entity_id' => 5],
                'https://example.com/admin/ordo/whatsapptemplate/refreshstatus/entity_id/5/',
            ]]);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $data = (new RefreshStatusButton($context))->getButtonData();

        self::assertSame('Refresh Status from Meta', (string) $data['label']);
        self::assertSame(30, $data['sort_order']);
        self::assertStringContainsString('entity_id/5', $data['on_click']);
        self::assertStringStartsWith('deleteConfirm(', $data['on_click']);
    }
}
