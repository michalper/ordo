<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate\SubmitForReview;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsApp\WhatsAppTemplateClient;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SubmitForReviewTest extends AbstractAdminActionTestCase
{
    private WhatsAppTemplateFactory $whatsAppTemplateFactory;
    private WhatsAppTemplateResource $whatsAppTemplateResource;
    private WhatsAppTemplateClient $whatsAppTemplateClient;

    protected function setUp(): void
    {
        $this->whatsAppTemplateFactory = $this->createMock(WhatsAppTemplateFactory::class);
        $this->whatsAppTemplateResource = $this->createMock(WhatsAppTemplateResource::class);
        $this->whatsAppTemplateClient = $this->createMock(WhatsAppTemplateClient::class);
    }

    private function makeController(): SubmitForReview
    {
        return new SubmitForReview(
            $this->makeContext(),
            $this->whatsAppTemplateFactory,
            $this->whatsAppTemplateResource,
            $this->whatsAppTemplateClient
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToGridWithErrorWhenTemplateNotFound(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createStub(WhatsAppTemplate::class);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateClient->expects(self::never())->method('submitTemplate');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSubmitsAndMovesToPendingOnSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/edit', ['entity_id' => 5])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createMock(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(5);
        $template->method('getMetaTemplateName')->willReturn('order_shipped_v1');
        $template->method('getCategory')->willReturn('utility');
        $template->method('getLanguage')->willReturn('en_US');
        $template->method('getBodyText')->willReturn('Hi {{1}}');
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateClient->expects(self::once())->method('submitTemplate')
            ->with('order_shipped_v1', 'utility', 'en_US', 'Hi {{1}}')
            ->willReturn('meta-template-123');

        $template->expects(self::once())->method('setMetaTemplateId')->with('meta-template-123');
        $template->expects(self::once())->method('setStatus')->with(WhatsAppTemplate::STATUS_PENDING);
        $template->expects(self::once())->method('setRejectionReason')->with(null);
        $template->expects(self::once())->method('setSubmittedAt');
        $this->whatsAppTemplateResource->expects(self::once())->method('save')->with($template);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRefusesToResubmitAnAlreadyPendingTemplate(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/edit', ['entity_id' => 5])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createMock(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(5);
        $template->method('getStatus')->willReturn(WhatsAppTemplate::STATUS_PENDING);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateClient->expects(self::never())->method('submitTemplate');
        $this->whatsAppTemplateResource->expects(self::never())->method('save');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRefusesToResubmitAnAlreadyApprovedTemplate(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createMock(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(5);
        $template->method('getStatus')->willReturn(WhatsAppTemplate::STATUS_APPROVED);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateClient->expects(self::never())->method('submitTemplate');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenMetaRejectsSubmission(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $template = $this->createMock(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(5);
        $this->whatsAppTemplateFactory->method('create')->willReturn($template);

        $this->whatsAppTemplateClient->method('submitTemplate')
            ->willThrowException(new \RuntimeException('Meta API error'));

        $this->whatsAppTemplateResource->expects(self::never())->method('save');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($redirect, $controller->execute());
    }
}
