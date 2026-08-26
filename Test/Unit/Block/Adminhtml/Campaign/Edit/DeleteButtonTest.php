<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Campaign\Edit\DeleteButton;
use PHPUnit\Framework\TestCase;

class DeleteButtonTest extends TestCase
{
    public function testGetButtonDataReturnsEmptyWhenNoEntityId(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('entity_id')->willReturn(null);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        self::assertSame([], (new DeleteButton($context))->getButtonData());
    }

    public function testGetButtonDataIncludesDeleteUrlWhenEntityIdPresent(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('entity_id')->willReturn(5);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->with('*/*/delete', ['entity_id' => 5])
            ->willReturn('https://example.com/admin/ordo/campaign/delete/entity_id/5/');

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $data = (new DeleteButton($context))->getButtonData();

        self::assertSame('Delete', (string) $data['label']);
        self::assertSame(20, $data['sort_order']);
        self::assertStringContainsString('entity_id/5', $data['on_click']);
    }
}
