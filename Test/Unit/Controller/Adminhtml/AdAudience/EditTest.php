<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\AdAudience;

use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\AdAudience\Edit;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\AdAudienceFactory;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
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
    public function testExecuteBuildsNewAdAudiencePageWhenNoEntityId(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 0]]);

        $adAudience = $this->createStub(AdAudience::class);
        $adAudienceFactory = $this->createStub(AdAudienceFactory::class);
        $adAudienceFactory->method('create')->willReturn($adAudience);

        $adAudienceResource = $this->createMock(AdAudienceResource::class);
        $adAudienceResource->expects(self::never())->method('load');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_ad_audience', $adAudience);

        $resultPage = $this->makeResultPage('New Ad Audience');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $adAudienceFactory, $adAudienceResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingAdAudience(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $adAudience = $this->createStub(AdAudience::class);
        $adAudience->method('getEntityId')->willReturn(5);
        $adAudience->method('getName')->willReturn('Test Audience');

        $adAudienceFactory = $this->createStub(AdAudienceFactory::class);
        $adAudienceFactory->method('create')->willReturn($adAudience);

        $adAudienceResource = $this->createMock(AdAudienceResource::class);
        $adAudienceResource->expects(self::once())->method('load')->with($adAudience, 5);

        $registry = $this->createStub(Registry::class);

        $resultPage = $this->makeResultPage('Edit Ad Audience "Test Audience"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $adAudienceFactory, $adAudienceResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWhenAdAudienceNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 99]]);

        $adAudience = $this->createStub(AdAudience::class);
        $adAudience->method('getEntityId')->willReturn(null);

        $adAudienceFactory = $this->createStub(AdAudienceFactory::class);
        $adAudienceFactory->method('create')->willReturn($adAudience);

        $adAudienceResource = $this->createStub(AdAudienceResource::class);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $adAudienceFactory, $adAudienceResource);
        self::assertSame($redirect, $controller->execute());
    }
}
