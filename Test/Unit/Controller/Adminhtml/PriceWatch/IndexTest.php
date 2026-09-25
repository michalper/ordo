<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\PriceWatch;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\PriceWatch\Index;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class IndexTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsResultPage(): void
    {
        $context = $this->makeContext();

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(__('Price Watch Subscriptions'));

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createMock(Page::class);
        $resultPage->expects(self::once())
            ->method('setActiveMenu')
            ->with('Ordo_Automation::price_watch_subscriptions')
            ->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        $resultPageFactory = $this->createStub(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Index($context, $resultPageFactory);
        self::assertSame($resultPage, $controller->execute());
    }

    public function testUsesPriceWatchSubscriptionsAclResource(): void
    {
        self::assertSame('Ordo_Automation::price_watch_subscriptions', Index::ADMIN_RESOURCE);
    }
}
