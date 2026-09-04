<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ScoreRule;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\ScoreRule\Edit;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRuleFactory;
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
    public function testExecuteBuildsNewScoreRulePageWhenNoEntityId(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 0]]);

        $scoreRule = $this->createStub(ScoreRule::class);
        $scoreRuleFactory = $this->createStub(ScoreRuleFactory::class);
        $scoreRuleFactory->method('create')->willReturn($scoreRule);

        $scoreRuleResource = $this->createMock(ScoreRuleResource::class);
        $scoreRuleResource->expects(self::never())->method('load');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_score_rule', $scoreRule);

        $resultPage = $this->makeResultPage('New Score Rule');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $scoreRuleFactory, $scoreRuleResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingScoreRule(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $scoreRule = $this->createStub(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(5);
        $scoreRule->method('getAttributeCode')->willReturn('group_id');

        $scoreRuleFactory = $this->createStub(ScoreRuleFactory::class);
        $scoreRuleFactory->method('create')->willReturn($scoreRule);

        $scoreRuleResource = $this->createMock(ScoreRuleResource::class);
        $scoreRuleResource->expects(self::once())->method('load')->with($scoreRule, 5);

        $registry = $this->createStub(Registry::class);

        $resultPage = $this->makeResultPage('Edit Score Rule "group_id"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $scoreRuleFactory, $scoreRuleResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWhenScoreRuleNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 99]]);

        $scoreRule = $this->createStub(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(null);

        $scoreRuleFactory = $this->createStub(ScoreRuleFactory::class);
        $scoreRuleFactory->method('create')->willReturn($scoreRule);

        $scoreRuleResource = $this->createStub(ScoreRuleResource::class);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $scoreRuleFactory, $scoreRuleResource);
        self::assertSame($redirect, $controller->execute());
    }
}
