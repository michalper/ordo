<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\Campaign\Edit;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;

class EditTest extends AbstractAdminActionTestCase
{
    private function makeResultPage(string $expectedTitle): Page
    {
        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(self::callback(
            fn ($phrase) => (string) $phrase === $expectedTitle
        ));

        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createMock(Page::class);
        $resultPage->method('setActiveMenu')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        return $resultPage;
    }

    public function testExecuteBuildsNewCampaignPageWhenNoEntityId(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(0);

        $campaign = $this->createMock(Campaign::class);
        $campaignFactory = $this->createMock(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $campaignResource = $this->createMock(CampaignResource::class);
        $campaignResource->expects(self::never())->method('load');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_campaign', $campaign);

        $resultPage = $this->makeResultPage('New Campaign');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $campaignFactory, $campaignResource);
        self::assertSame($resultPage, $controller->execute());
    }

    public function testExecuteLoadsExistingCampaign(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $campaign->method('getName')->willReturn('Welcome');

        $campaignFactory = $this->createMock(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $campaignResource = $this->createMock(CampaignResource::class);
        $campaignResource->expects(self::once())->method('load')->with($campaign, 5);

        $registry = $this->createMock(Registry::class);

        $resultPage = $this->makeResultPage('Edit Campaign "Welcome"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $campaignFactory, $campaignResource);
        self::assertSame($resultPage, $controller->execute());
    }

    public function testExecuteRedirectsWhenCampaignNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(99);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);

        $campaignFactory = $this->createMock(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $campaignResource = $this->createMock(CampaignResource::class);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $campaignFactory, $campaignResource);
        self::assertSame($redirect, $controller->execute());
    }
}
