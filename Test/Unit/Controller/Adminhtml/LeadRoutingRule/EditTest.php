<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\LeadRoutingRule\Edit;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
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
    public function testExecuteBuildsNewRulePageWhenNoEntityId(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 0]]);

        $leadRoutingRule = $this->createStub(LeadRoutingRule::class);
        $leadRoutingRuleFactory = $this->createStub(LeadRoutingRuleFactory::class);
        $leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $leadRoutingRuleResource = $this->createMock(LeadRoutingRuleResource::class);
        $leadRoutingRuleResource->expects(self::never())->method('load');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_lead_routing_rule', $leadRoutingRule);

        $resultPage = $this->makeResultPage('New Lead Routing Rule');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $leadRoutingRuleFactory, $leadRoutingRuleResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingRule(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $leadRoutingRule = $this->createStub(LeadRoutingRule::class);
        $leadRoutingRule->method('getEntityId')->willReturn(5);
        $leadRoutingRule->method('getName')->willReturn('EU B2B leads');

        $leadRoutingRuleFactory = $this->createStub(LeadRoutingRuleFactory::class);
        $leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $leadRoutingRuleResource = $this->createMock(LeadRoutingRuleResource::class);
        $leadRoutingRuleResource->expects(self::once())->method('load')->with($leadRoutingRule, 5);

        $registry = $this->createStub(Registry::class);

        $resultPage = $this->makeResultPage('Edit Lead Routing Rule "EU B2B leads"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $leadRoutingRuleFactory, $leadRoutingRuleResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWhenRuleNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 99]]);

        $leadRoutingRule = $this->createStub(LeadRoutingRule::class);
        $leadRoutingRule->method('getEntityId')->willReturn(null);

        $leadRoutingRuleFactory = $this->createStub(LeadRoutingRuleFactory::class);
        $leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $leadRoutingRuleResource = $this->createStub(LeadRoutingRuleResource::class);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $leadRoutingRuleFactory, $leadRoutingRuleResource);
        self::assertSame($redirect, $controller->execute());
    }
}
