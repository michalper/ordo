<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Customer360;

use Magento\Backend\Model\View\Result\Page;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\CustomerLookupByEmailRegistrar;
use Ordo\Automation\Controller\Adminhtml\Customer360\Index;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class IndexTest extends AbstractAdminActionTestCase
{
    private function makeResultPage(): Page
    {
        $title = $this->createStub(Title::class);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createStub(Page::class);
        $resultPage->method('getConfig')->willReturn($pageConfig);

        return $resultPage;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsResultPageWithoutSearching(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['email', null]]);

        $resultPageFactory = $this->createStub(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($this->makeResultPage());

        $customerLookup = $this->createMock(CustomerLookupByEmailRegistrar::class);
        $customerLookup->expects(self::never())->method('resolveAndRegister');

        $controller = new Index($context, $resultPageFactory, $customerLookup);
        self::assertInstanceOf(Page::class, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRegistersCustomerWhenFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['email', 'jan@example.com']]);

        $resultPageFactory = $this->createStub(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($this->makeResultPage());

        $customer = $this->createStub(CustomerInterface::class);
        $customerLookup = $this->createMock(CustomerLookupByEmailRegistrar::class);
        $customerLookup->expects(self::once())
            ->method('resolveAndRegister')
            ->with('jan@example.com', 'ordo_customer_360')
            ->willReturn($customer);

        $controller = new Index($context, $resultPageFactory, $customerLookup);
        self::assertInstanceOf(Page::class, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorMessageWhenCustomerNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['email', 'nobody@example.com']]);

        $resultPageFactory = $this->createStub(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($this->makeResultPage());

        $customerLookup = $this->createMock(CustomerLookupByEmailRegistrar::class);
        $customerLookup->method('resolveAndRegister')->willReturn(null);
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new Index($context, $resultPageFactory, $customerLookup);
        self::assertInstanceOf(Page::class, $controller->execute());
    }
}
