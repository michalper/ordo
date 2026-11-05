<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate\Save;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private WhatsAppTemplateFactory $whatsAppTemplateFactory;
    private WhatsAppTemplateResource $whatsAppTemplateResource;

    protected function setUp(): void
    {
        $this->whatsAppTemplateFactory = $this->createMock(WhatsAppTemplateFactory::class);
        $this->whatsAppTemplateResource = $this->createMock(WhatsAppTemplateResource::class);
    }

    private function makeController(): Save
    {
        return new Save($this->makeContext(), $this->whatsAppTemplateFactory, $this->whatsAppTemplateResource);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->whatsAppTemplateFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewTemplateAsDraftAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'name' => 'Order Shipped',
            'meta_template_name' => 'order_shipped_v1',
            'category' => 'utility',
            'language' => 'en_US',
            'body_text' => 'Hi {{1}}, your order {{2}} shipped',
            'placeholder_legend' => '{{1}} = first name, {{2}} = order number',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createMock(WhatsAppTemplate::class);
        $template->method('getBodyText')->willReturn('');
        $template->expects(self::once())->method('setName')->with('Order Shipped');
        $template->expects(self::once())->method('setMetaTemplateName')->with('order_shipped_v1');
        $template->expects(self::once())->method('setCategory')->with('utility');
        $template->expects(self::once())->method('setLanguage')->with('en_US');
        $template->expects(self::once())->method('setBodyText')->with('Hi {{1}}, your order {{2}} shipped');
        $template->expects(self::once())->method('setPlaceholderLegend')
            ->with('{{1}} = first name, {{2}} = order number');
        $template->expects(self::once())->method('setStatus')->with(WhatsAppTemplate::STATUS_DRAFT);
        $template->expects(self::once())->method('setMetaTemplateId')->with(null);
        $template->expects(self::once())->method('setRejectionReason')->with(null);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateResource->expects(self::never())->method('load');
        $this->whatsAppTemplateResource->expects(self::once())->method('save')->with($template);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteResetsToDraftWhenBodyTextChangedOnExistingTemplate(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => '7',
            'name' => 'Order Shipped',
            'meta_template_name' => 'order_shipped_v1',
            'category' => 'utility',
            'language' => 'en_US',
            'body_text' => 'New body text',
            'back' => '1',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null, '1']]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createMock(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(7);
        $template->method('getBodyText')->willReturn('Old body text');
        $template->expects(self::once())->method('setStatus')->with(WhatsAppTemplate::STATUS_DRAFT);
        $template->expects(self::once())->method('setMetaTemplateId')->with(null);
        $template->expects(self::once())->method('setRejectionReason')->with(null);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateResource->expects(self::once())->method('load')->with($template, 7);
        $this->whatsAppTemplateResource->expects(self::once())->method('save')->with($template);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteKeepsStatusWhenBodyTextUnchangedOnExistingTemplate(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => '7',
            'name' => 'Order Shipped',
            'meta_template_name' => 'order_shipped_v1',
            'category' => 'utility',
            'language' => 'en_US',
            'body_text' => 'Same body text',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createMock(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(7);
        $template->method('getBodyText')->willReturn('Same body text');
        $template->expects(self::never())->method('setStatus');
        $template->expects(self::never())->method('setMetaTemplateId');
        $template->expects(self::never())->method('setRejectionReason');
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateResource->expects(self::once())->method('save')->with($template);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 0, 'name' => 'X']);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createStub(WhatsAppTemplate::class);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);
        $this->whatsAppTemplateResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($redirect, $controller->execute());
    }
}
