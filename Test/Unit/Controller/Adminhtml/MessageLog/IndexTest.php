<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\MessageLog;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\MessageLog\Index;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class IndexTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsResultPage(): void
    {
        $context = $this->makeContext();

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(__('Message Log'));

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createMock(Page::class);
        $resultPage->expects(self::once())
            ->method('setActiveMenu')
            ->with('Ordo_Automation::campaigns')
            ->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        $resultPageFactory = $this->createStub(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Index($context, $resultPageFactory);
        self::assertSame($resultPage, $controller->execute());
    }

    public function testUsesCampaignsAclResource(): void
    {
        self::assertSame('Ordo_Automation::campaigns', Index::ADMIN_RESOURCE);
    }
}
