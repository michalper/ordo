<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft\Save;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private EmailTemplateDraftFactory $draftFactory;
    private EmailTemplateDraftResource $draftResource;
    private Save $controller;

    protected function setUp(): void
    {
        $this->draftFactory = $this->createMock(EmailTemplateDraftFactory::class);
        $this->draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $this->controller = new Save($this->makeContext(), $this->draftFactory, $this->draftResource);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->draftFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenDraftNotFound(): void
    {
        $postData = ['entity_id' => 99, 'template_subject' => 'New subject'];
        $this->request->method('getPostValue')->willReturn($postData);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(null);
        $this->draftFactory->method('create')->willReturn($draft);

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('This draft no longer exists.'));
        $this->draftResource->expects(self::never())->method('save');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesDraftContentAndRedirectsToGrid(): void
    {
        $postData = [
            'entity_id' => '7',
            'template_subject' => 'New subject',
            'template_text' => '<p>New body</p>',
            'template_styles' => '.a { color: blue; }',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createMock(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(7);
        $draft->expects(self::once())->method('setTemplateSubject')->with('New subject');
        $draft->expects(self::once())->method('setTemplateText')->with('<p>New body</p>');
        $draft->expects(self::once())->method('setTemplateStyles')->with('.a { color: blue; }');
        $this->draftFactory->method('create')->willReturn($draft);

        $this->draftResource->expects(self::once())->method('load')->with($draft, 7);
        $this->draftResource->expects(self::once())->method('save')->with($draft);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsBackToEditWhenBackParamSet(): void
    {
        $postData = ['entity_id' => '7', 'template_subject' => 'X', 'template_text' => 'Y'];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null, '1']]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createMock(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(7);
        $this->draftFactory->method('create')->willReturn($draft);

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenSaveThrows(): void
    {
        $postData = ['entity_id' => '7', 'template_subject' => 'X', 'template_text' => 'Y'];
        $this->request->method('getPostValue')->willReturn($postData);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createMock(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(7);
        $this->draftFactory->method('create')->willReturn($draft);
        $this->draftResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('Could not save the email template draft: %1', 'db down'));

        self::assertSame($redirect, $this->controller->execute());
    }
}
