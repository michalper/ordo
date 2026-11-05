<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\Template as MagentoTemplate;
use Magento\Email\Model\TemplateFactory as MagentoTemplateFactory;
use Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft\Publish;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class PublishTest extends AbstractAdminActionTestCase
{
    private EmailTemplateDraftFactory $draftFactory;
    private EmailTemplateDraftResource $draftResource;
    private MagentoTemplateFactory $magentoTemplateFactory;
    private MagentoTemplateResource $magentoTemplateResource;
    private Publish $controller;

    protected function setUp(): void
    {
        $this->draftFactory = $this->createMock(EmailTemplateDraftFactory::class);
        $this->draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $this->magentoTemplateFactory = $this->createMock(MagentoTemplateFactory::class);
        $this->magentoTemplateResource = $this->createMock(MagentoTemplateResource::class);
        $this->controller = new Publish(
            $this->makeContext(),
            $this->draftFactory,
            $this->draftResource,
            $this->magentoTemplateFactory,
            $this->magentoTemplateResource
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenDraftNotFound(): void
    {
        $this->request->method('getParam')->willReturnMap([['entity_id', 99]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(null);
        $this->draftFactory->method('create')->willReturn($draft);

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('This draft no longer exists.'));
        $this->magentoTemplateFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenLiveTemplateMissing(): void
    {
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(5);
        $draft->method('getTemplateId')->willReturn(7);
        $this->draftFactory->method('create')->willReturn($draft);

        $liveTemplate = $this->createStub(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(null);
        $this->magentoTemplateFactory->method('create')->willReturn($liveTemplate);

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('The live template this draft belongs to no longer exists.'));
        $this->draftResource->expects(self::never())->method('delete');

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecutePublishesDraftOntoLiveTemplateAndDeletesDraft(): void
    {
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(5);
        $draft->method('getTemplateId')->willReturn(7);
        $draft->method('getTemplateCode')->willReturn('welcome_email');
        $draft->method('getTemplateSubject')->willReturn('New subject');
        $draft->method('getTemplateText')->willReturn('<p>New body</p>');
        $draft->method('getTemplateStyles')->willReturn('.a { color: blue; }');
        $this->draftFactory->method('create')->willReturn($draft);

        $liveTemplate = $this->createMock(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(7);
        $liveTemplate->expects(self::exactly(3))->method('setData')->with(
            self::callback(static fn ($field) => in_array($field, ['template_subject', 'template_text', 'template_styles'], true))
        );
        $this->magentoTemplateFactory->method('create')->willReturn($liveTemplate);

        $this->magentoTemplateResource->expects(self::once())->method('load')->with($liveTemplate, 7);
        $this->magentoTemplateResource->expects(self::once())->method('save')->with($liveTemplate);
        $this->draftResource->expects(self::once())->method('delete')->with($draft);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('Published the draft to template "%1".', 'welcome_email'));

        self::assertSame($redirect, $this->controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReportsErrorWhenSaveThrowsAndLeavesDraftIntact(): void
    {
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(5);
        $draft->method('getTemplateId')->willReturn(7);
        $this->draftFactory->method('create')->willReturn($draft);

        $liveTemplate = $this->createStub(MagentoTemplate::class);
        $liveTemplate->method('getId')->willReturn(7);
        $this->magentoTemplateFactory->method('create')->willReturn($liveTemplate);

        $this->magentoTemplateResource->method('save')->willThrowException(new \RuntimeException('db down'));
        $this->draftResource->expects(self::never())->method('delete');

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('Could not publish this draft: %1', 'db down'));
        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        self::assertSame($redirect, $this->controller->execute());
    }
}
