<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft\Edit;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class EditTest extends AbstractAdminActionTestCase
{
    private function makeResultPage(string $expectedTitle): Page
    {
        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(self::callback(
            fn ($phrase) => (string) $phrase === $expectedTitle
        ));

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createStub(Page::class);
        $resultPage->method('setActiveMenu')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        return $resultPage;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 0]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $draftFactory = $this->createMock(EmailTemplateDraftFactory::class);
        $draftFactory->expects(self::never())->method('create');
        $draftResource = $this->createStub(EmailTemplateDraftResource::class);
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $draftFactory, $draftResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenDraftNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 99]]);

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(null);

        $draftFactory = $this->createStub(EmailTemplateDraftFactory::class);
        $draftFactory->method('create')->willReturn($draft);

        $draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $draftResource->expects(self::once())->method('load')->with($draft, 99);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $draftFactory, $draftResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingDraft(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draft->method('getEntityId')->willReturn(5);
        $draft->method('getTemplateCode')->willReturn('welcome_email');

        $draftFactory = $this->createStub(EmailTemplateDraftFactory::class);
        $draftFactory->method('create')->willReturn($draft);

        $draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $draftResource->expects(self::once())->method('load')->with($draft, 5);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_email_template_draft', $draft);

        $resultPage = $this->makeResultPage('Edit Email Template Draft "welcome_email"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $draftFactory, $draftResource);
        self::assertSame($resultPage, $controller->execute());
    }
}
