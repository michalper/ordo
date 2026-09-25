<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Customer360;

use Magento\Backend\Model\View\Result\Page;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
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

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::never())->method('get');
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $controller = new Index($context, $resultPageFactory, $customerRepository, $registry);
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
        $customer->method('getId')->willReturn(42);
        $customer->method('getEmail')->willReturn('jan@example.com');
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::once())->method('get')->with('jan@example.com')->willReturn($customer);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::exactly(2))->method('register');

        $controller = new Index($context, $resultPageFactory, $customerRepository, $registry);
        self::assertInstanceOf(Page::class, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorMessageWhenCustomerNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['email', 'nobody@example.com']]);

        $resultPageFactory = $this->createStub(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($this->makeResultPage());

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('get')->willThrowException(new NoSuchEntityException(__('no such customer')));
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new Index($context, $resultPageFactory, $customerRepository, $registry);
        self::assertInstanceOf(Page::class, $controller->execute());
    }
}
