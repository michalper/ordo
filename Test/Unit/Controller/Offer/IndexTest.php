<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Offer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Ordo\Automation\Controller\Offer\Index;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class IndexTest extends AbstractFrontendActionTestCase
{
    private CustomerSession $customerSession;
    private CustomerUrl $customerUrl;
    private Page $resultPage;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->customerUrl = $this->createStub(CustomerUrl::class);
    }

    private function makeController(): Index
    {
        $this->makeContext();

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('set')->with(__('My Offers'));

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $this->resultPage = $this->createStub(Page::class);
        $this->resultPage->method('getConfig')->willReturn($pageConfig);

        $this->resultFactory = $this->createStub(ResultFactory::class);
        $this->resultFactory->method('create')->with(ResultFactory::TYPE_PAGE)->willReturn($this->resultPage);

        $context = $this->createStub(\Magento\Framework\App\Action\Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultFactory')->willReturn($this->resultFactory);

        return new Index($context, $this->customerSession, $this->customerUrl);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsResultPageWithTitle(): void
    {
        $controller = $this->makeController();

        self::assertSame($this->resultPage, $controller->execute());
    }
}
