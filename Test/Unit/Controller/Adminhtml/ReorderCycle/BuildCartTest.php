<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Controller\Adminhtml\ReorderCycle\BuildCart;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ReorderCycle\ReorderCartBuilder;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class BuildCartTest extends AbstractAdminActionTestCase
{
    private ReorderCycleFactory $reorderCycleFactory;
    private ReorderCycleResource $reorderCycleResource;
    private CustomerRepositoryInterface $customerRepository;
    private ReorderCartBuilder $reorderCartBuilder;
    private Redirect $redirect;

    protected function setUp(): void
    {
        $this->reorderCycleFactory = $this->createMock(ReorderCycleFactory::class);
        $this->reorderCycleResource = $this->createStub(ReorderCycleResource::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->reorderCartBuilder = $this->createMock(ReorderCartBuilder::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
    }

    /**
     * Same makeContext()-before-configuring-resultRedirectFactory ordering
     * Test\Unit\Controller\Adminhtml\ReorderCycle\SendReminderTest's own docblock explains.
     */
    private function makeController(): BuildCart
    {
        $context = $this->makeContext();
        $this->resultRedirectFactory->method('create')->willReturn($this->redirect);

        return new BuildCart(
            $context,
            $this->reorderCycleFactory,
            $this->reorderCycleResource,
            $this->customerRepository,
            $this->reorderCartBuilder
        );
    }

    private function makeCycle(?int $entityId, int $customerId = 7): ReorderCycle
    {
        $cycle = $this->createStub(ReorderCycle::class);
        $cycle->method('getEntityId')->willReturn($entityId);
        $cycle->method('getCustomerId')->willReturn($customerId);

        return $cycle;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsErrorWhenEntityIdMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, null]]);

        $this->reorderCycleFactory->expects(self::never())->method('create');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsErrorWhenCycleNoLongerExists(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $this->reorderCycleFactory->method('create')->willReturn($this->makeCycle(null));

        $this->customerRepository->expects(self::never())->method('getById');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsCartAndRedirectsToOrderCreateOnSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $cycle = $this->makeCycle(5, 7);
        $this->reorderCycleFactory->method('create')->willReturn($cycle);

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepository->method('getById')->with(7)->willReturn($customer);

        $this->reorderCartBuilder->expects(self::once())->method('build')->with($cycle, $customer);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        $setPaths = [];
        $this->redirect->method('setPath')->willReturnCallback(function (string $path) use (&$setPaths) {
            $setPaths[] = $path;
            return $this->redirect;
        });

        self::assertSame($this->redirect, $controller->execute());
        // Set unconditionally at the top (the shared error-path default), then overridden with
        // the real success destination once the cart actually got built.
        self::assertSame(['ordo/reordercycle/index', 'sales/order_create/index'], $setPaths);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsErrorWhenCustomerNoLongerExists(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $this->reorderCycleFactory->method('create')->willReturn($this->makeCycle(5, 7));
        $this->customerRepository->method('getById')->willThrowException(
            new NoSuchEntityException(__('no such customer'))
        );

        $this->reorderCartBuilder->expects(self::never())->method('build');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsErrorWhenProductNoLongerExists(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $this->reorderCycleFactory->method('create')->willReturn($this->makeCycle(5, 7));

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepository->method('getById')->willReturn($customer);
        $this->reorderCartBuilder->method('build')->willThrowException(
            new NoSuchEntityException(__('no such product'))
        );

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsErrorWhenBuildingTheCartFails(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $this->reorderCycleFactory->method('create')->willReturn($this->makeCycle(5, 7));

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepository->method('getById')->willReturn($customer);
        $this->reorderCartBuilder->method('build')->willThrowException(
            new LocalizedException(__('out of stock'))
        );

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(self::callback(fn ($message) => str_contains((string) $message, 'out of stock')));

        self::assertSame($this->redirect, $controller->execute());
    }
}
