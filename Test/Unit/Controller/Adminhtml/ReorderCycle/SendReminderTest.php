<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Controller\Adminhtml\ReorderCycle\SendReminder;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ReorderCycle\OptedOutException;
use Ordo\Automation\Model\ReorderCycle\ReorderReminderSender;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendReminderTest extends AbstractAdminActionTestCase
{
    private ReorderCycleFactory $reorderCycleFactory;
    private ReorderCycleResource $reorderCycleResource;
    private CustomerRepositoryInterface $customerRepository;
    private ReorderReminderSender $reminderSender;
    private Redirect $redirect;

    protected function setUp(): void
    {
        $this->reorderCycleFactory = $this->createStub(ReorderCycleFactory::class);
        $this->reorderCycleResource = $this->createStub(ReorderCycleResource::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->reminderSender = $this->createMock(ReorderReminderSender::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
    }

    /**
     * makeContext() (re)assigns $this->resultRedirectFactory to a fresh stub every call - must
     * be called and its return value handed to the controller BEFORE configuring
     * resultRedirectFactory, or the configuration lands on an already-discarded instance and the
     * property is left uninitialized (a real bug found via a real CI failure, not just style).
     */
    private function makeController(): SendReminder
    {
        $context = $this->makeContext();
        $this->resultRedirectFactory->method('create')->willReturn($this->redirect);

        return new SendReminder(
            $context,
            $this->reorderCycleFactory,
            $this->reorderCycleResource,
            $this->customerRepository,
            $this->reminderSender
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
    public function testExecuteSendsReminderAndRedirectsWithSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $cycle = $this->makeCycle(5, 7);
        $this->reorderCycleFactory->method('create')->willReturn($cycle);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('jane@example.com');
        $this->customerRepository->method('getById')->with(7)->willReturn($customer);

        $this->reminderSender->expects(self::once())->method('sendNow')->with($cycle, $customer);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        self::assertSame($this->redirect, $controller->execute());
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

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSpecificErrorWhenCustomerOptedOut(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $cycle = $this->makeCycle(5, 7);
        $this->reorderCycleFactory->method('create')->willReturn($cycle);

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepository->method('getById')->willReturn($customer);
        $this->reminderSender->method('sendNow')->willThrowException(new OptedOutException('opted out'));

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(self::stringContains('opted out'));

        self::assertSame($this->redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsGenericErrorWhenSendThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null, 5]]);
        $cycle = $this->makeCycle(5, 7);
        $this->reorderCycleFactory->method('create')->willReturn($cycle);

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepository->method('getById')->willReturn($customer);
        $this->reminderSender->method('sendNow')->willThrowException(new \RuntimeException('smtp down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(self::stringContains('smtp down'));

        self::assertSame($this->redirect, $controller->execute());
    }
}
