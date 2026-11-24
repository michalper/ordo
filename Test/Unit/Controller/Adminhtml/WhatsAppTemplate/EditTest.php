<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate\Edit;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;
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

        $resultPage = $this->createMock(Page::class);
        $resultPage->expects(self::once())->method('setActiveMenu')->with('Ordo_Automation::campaigns')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        return $resultPage;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsNewTemplatePageWhenNoEntityId(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 0]]);

        $template = $this->createStub(WhatsAppTemplate::class);
        $templateFactory = $this->createStub(WhatsAppTemplateFactory::class);
        $templateFactory->method('create')->willReturn($template);

        $templateResource = $this->createMock(WhatsAppTemplateResource::class);
        $templateResource->expects(self::never())->method('load');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_whatsapp_template', $template);

        $resultPage = $this->makeResultPage('New WhatsApp Template');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $templateFactory, $templateResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingTemplate(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $template = $this->createStub(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(5);
        $template->method('getName')->willReturn('Order Shipped');

        $templateFactory = $this->createStub(WhatsAppTemplateFactory::class);
        $templateFactory->method('create')->willReturn($template);

        $templateResource = $this->createMock(WhatsAppTemplateResource::class);
        $templateResource->expects(self::once())->method('load')->with($template, 5);

        $registry = $this->createStub(Registry::class);

        $resultPage = $this->makeResultPage('Edit WhatsApp Template "Order Shipped"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $templateFactory, $templateResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWhenTemplateNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 99]]);

        $template = $this->createStub(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(null);

        $templateFactory = $this->createStub(WhatsAppTemplateFactory::class);
        $templateFactory->method('create')->willReturn($template);

        $templateResource = $this->createStub(WhatsAppTemplateResource::class);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $templateFactory, $templateResource);
        self::assertSame($redirect, $controller->execute());
    }
}
