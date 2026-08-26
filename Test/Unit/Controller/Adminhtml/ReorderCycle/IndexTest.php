<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ReorderCycle;

use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\ReorderCycle\Index;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;

class IndexTest extends AbstractAdminActionTestCase
{
    public function testExecuteBuildsResultPage(): void
    {
        $context = $this->makeContext();

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(__('Reorder Cycles'));

        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createMock(Page::class);
        $resultPage->expects(self::once())->method('setActiveMenu')->with('Ordo_Automation::reorder_cycles')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Index($context, $resultPageFactory);
        self::assertSame($resultPage, $controller->execute());
    }
}
