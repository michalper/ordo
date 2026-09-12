<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\Campaign\ScheduleCalendar;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ScheduleCalendarTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsResultPage(): void
    {
        $context = $this->makeContext();

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(__('Scheduled Campaign Calendar'));

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createMock(Page::class);
        $resultPage->expects(self::once())->method('setActiveMenu')->with('Ordo_Automation::top_level')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        $resultPageFactory = $this->createStub(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new ScheduleCalendar($context, $resultPageFactory);
        self::assertSame($resultPage, $controller->execute());
    }
}
