<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\DeleteButton;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DeleteButtonTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testGetButtonDataReturnsEmptyWhenNoEntityId(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['entity_id', null]]);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $button = new DeleteButton($context, 'Are you sure you want to delete this campaign?');

        self::assertSame([], $button->getButtonData());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetButtonDataIncludesConfirmMessageAndDeleteUrlWhenEntityIdPresent(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')
            ->willReturnMap([['*/*/delete', ['entity_id' => 5], 'https://example.com/admin/ordo/campaign/delete/entity_id/5/']]);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $button = new DeleteButton($context, 'Are you sure you want to delete this campaign?');
        $data = $button->getButtonData();

        self::assertSame('Delete', (string) $data['label']);
        self::assertSame(20, $data['sort_order']);
        self::assertStringContainsString('Are you sure you want to delete this campaign?', $data['on_click']);
        self::assertStringContainsString('entity_id/5', $data['on_click']);
    }
}
