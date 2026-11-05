<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\EmailTemplateDraft\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\EmailTemplateDraft\Edit\PublishButton;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class PublishButtonTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testGetButtonDataReturnsEmptyWhenNoEntityId(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['entity_id', null]]);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        self::assertSame([], (new PublishButton($context))->getButtonData());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetButtonDataIncludesPublishUrlWhenEntityIdPresent(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnMap([
            ['*/*/publish', ['entity_id' => 5], 'https://example.com/admin/ordo/emailtemplatedraft/publish/entity_id/5/'],
        ]);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $data = (new PublishButton($context))->getButtonData();

        self::assertSame('Publish', (string) $data['label']);
        self::assertSame(40, $data['sort_order']);
        self::assertStringContainsString('entity_id/5', $data['on_click']);
        self::assertStringStartsWith('deleteConfirm(', $data['on_click']);
        self::assertStringContainsString(
            '{data: {}}',
            $data['on_click'],
            'Publish must submit a real POST (deleteConfirm\'s 3-arg form) - the controller is '
            . 'HttpPostActionInterface-only, so the 2-arg form\'s plain GET 404s before reaching it.'
        );
    }
}
