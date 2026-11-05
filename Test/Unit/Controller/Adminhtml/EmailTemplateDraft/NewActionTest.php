<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\Template as MagentoTemplate;
use Magento\Email\Model\TemplateFactory as MagentoTemplateFactory;
use Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft\NewAction;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class NewActionTest extends AbstractAdminActionTestCase
{
    private MagentoTemplateFactory $magentoTemplateFactory;
    private MagentoTemplateResource $magentoTemplateResource;
    private EmailTemplateDraftFactory $draftFactory;
    private EmailTemplateDraftResource $draftResource;
    private NewAction $controller;

    protected function setUp(): void
    {
        $this->magentoTemplateFactory = $this->createMock(MagentoTemplateFactory::class);
        $this->magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $this->draftFactory = $this->createMock(EmailTemplateDraftFactory::class);
        $this->draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $this->controller = new NewAction(
            $this->makeContext(),
            $this->magentoTemplateFactory,
            $this->magentoTemplateResource,
            $this->draftFactory,
            $this->draftResource
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenTemplateIdMissing(): void
    {
        $this->request->method('getParam')->willReturnMap([['template_id', 0]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->magentoTemplateFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenLiveTemplateMissing(): void
    {
        $this->request->method('getParam')->willReturnMap([['template_id', 7]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $liveTemplate = $this->createStub(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(null);
        $this->magentoTemplateFactory->method('create')->willReturn($liveTemplate);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->draftFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToExistingDraftWhenOneAlreadyExists(): void
    {
        $this->request->method('getParam')->willReturnMap([['template_id', 7]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/edit', ['entity_id' => 42])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $liveTemplate = $this->createStub(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(7);

        $existingDraft = $this->createStub(EmailTemplateDraft::class);
        $existingDraft->method('getEntityId')->willReturn(42);

        $this->magentoTemplateFactory->method('create')->willReturn($liveTemplate);
        $this->draftFactory->method('create')->willReturn($existingDraft);

        $this->draftResource->expects(self::once())->method('load')
            ->with($existingDraft, 7, EmailTemplateDraft::TEMPLATE_ID);
        $this->draftResource->expects(self::never())->method('save');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCreatesDraftCopyingLiveTemplateContent(): void
    {
        $this->request->method('getParam')->willReturnMap([['template_id', 7]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/edit', ['entity_id' => 99])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $liveTemplate = $this->createStub(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(7);
        $liveTemplate->method('getData')->willReturnMap([
            ['template_code', null, 'welcome_email'],
            ['template_subject', null, 'Welcome!'],
            ['template_text', null, '<p>Hello</p>'],
            ['template_styles', null, '.a { color: red; }'],
        ]);

        $newDraft = $this->createMock(EmailTemplateDraft::class);
        $newDraft->expects(self::once())->method('setTemplateId')->with(7);
        $newDraft->expects(self::once())->method('setTemplateCode')->with('welcome_email');
        $newDraft->expects(self::once())->method('setTemplateSubject')->with('Welcome!');
        $newDraft->expects(self::once())->method('setTemplateText')->with('<p>Hello</p>');
        $newDraft->expects(self::once())->method('setTemplateStyles')->with('.a { color: red; }');
        $newDraft->method('getEntityId')->willReturn(99);

        $existingDraft = $this->createStub(EmailTemplateDraft::class);
        $existingDraft->method('getEntityId')->willReturn(null);

        $this->magentoTemplateFactory->method('create')->willReturn($liveTemplate);
        $this->draftFactory->method('create')->willReturnOnConsecutiveCalls($existingDraft, $newDraft);

        $this->draftResource->expects(self::once())->method('save')->with($newDraft);

        self::assertSame($redirect, $this->controller->execute());
    }
}
