<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\Store;
use Ordo\Automation\Api\Data\OrderApprovalDecisionLinksInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Observer\HoldOrderForApproval;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSpendLimitAttributes;
use Ordo\Automation\Setup\Patch\Data\AddPendingApprovalOrderStatus;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HoldOrderForApprovalTest extends TestCase
{
    private Config $config;
    private CustomerRepositoryInterface $customerRepository;
    private OrderResource $orderResource;
    private OrderApprovalFactory $orderApprovalFactory;
    private OrderApprovalResource $orderApprovalResource;
    private Random $random;
    private ReminderEmailSender $reminderEmailSender;
    private OrderApprovalManagement $orderApprovalManagement;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isOrderApprovalEnabled')->willReturn(true);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->orderResource = $this->createMock(OrderResource::class);
        $this->orderApprovalFactory = $this->createStub(OrderApprovalFactory::class);
        $this->orderApprovalResource = $this->createMock(OrderApprovalResource::class);
        $this->random = $this->createStub(Random::class);
        $this->reminderEmailSender = $this->createStub(ReminderEmailSender::class);
        $this->orderApprovalManagement = $this->createStub(OrderApprovalManagement::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeObserverInstance(): HoldOrderForApproval
    {
        return new HoldOrderForApproval(
            $this->config,
            $this->customerRepository,
            $this->orderResource,
            $this->orderApprovalFactory,
            $this->orderApprovalResource,
            $this->random,
            $this->reminderEmailSender,
            $this->orderApprovalManagement,
            $this->logger
        );
    }

    private function makeEventObserver(?Order $order): EventObserver
    {
        $event = new Event(['order' => $order]);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject&Order $order
     */
    private function stubOrderStore($order, int $websiteId = 1): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getId')->willReturn($websiteId);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $order->method('getStore')->willReturn($store);

        return $store;
    }

    private function expectApprovalEmailSentFor(Store $store, string $token, string $adminEmail): void
    {
        $links = $this->createStub(OrderApprovalDecisionLinksInterface::class);
        $links->method('getApproveUrl')->willReturn('https://example.com/ordo/approval/approve/token/' . $token);
        $links->method('getRejectUrl')->willReturn('https://example.com/ordo/approval/reject/token/' . $token);

        $management = $this->createMock(OrderApprovalManagement::class);
        $management->expects(self::once())->method('buildDecisionLinks')
            ->with(self::identicalTo($store), $token)
            ->willReturn($links);
        $this->orderApprovalManagement = $management;

        $sender = $this->createMock(ReminderEmailSender::class);
        $sender->expects(self::once())->method('send')->with(
            'ordo_order_approval_request',
            [
                'order_increment_id' => '000000007',
                'order_total' => 500.0,
                'customer_name' => 'Jan Kowalski',
                'approve_url' => 'https://example.com/ordo/approval/approve/token/' . $token,
                'reject_url' => 'https://example.com/ordo/approval/reject/token/' . $token,
            ],
            $adminEmail,
            '',
            self::identicalTo($store)
        );
        $this->reminderEmailSender = $sender;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenApprovalDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(false);
        $this->config = $config;

        $this->customerRepository->expects(self::never())->method('getById');

        $this->makeObserverInstance()->execute($this->makeEventObserver(null));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingForAGuestOrderWithNoMatchingCustomerAccount(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getCustomerEmail')->willReturn('oneoff-guest@example.com');
        $this->stubOrderStore($order);

        $this->customerRepository->expects(self::never())->method('getById');
        $this->customerRepository->expects(self::once())->method('get')
            ->with('oneoff-guest@example.com', 1)
            ->willThrowException(new LocalizedException(__('no such customer')));

        $this->orderResource->expects(self::never())->method('save');

        $this->makeObserverInstance()->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenEventHasNoOrder(): void
    {
        $this->customerRepository->expects(self::never())->method('getById');
        $this->customerRepository->expects(self::never())->method('get');

        $this->makeObserverInstance()->execute($this->makeEventObserver(null));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingForAGuestOrderWithNoEmail(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getCustomerEmail')->willReturn('');

        $this->customerRepository->expects(self::never())->method('getById');
        $this->customerRepository->expects(self::never())->method('get');

        $this->orderResource->expects(self::never())->method('save');

        $this->makeObserverInstance()->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteHoldsAGuestOrderWhoseEmailMatchesACustomerWithASpendLimit(): void
    {
        // The real bypass this closes: an admin configures a spend limit/approval email on a
        // registered customer's account, and that customer simply checks out as a guest (same
        // email, no login) to dodge it. getCustomerId() stays null for a guest order, so this
        // must fall back to resolving the customer by email instead.
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getCustomerEmail')->willReturn('vip@example.com');
        $order->method('getGrandTotal')->willReturn(500.0);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getCustomerFirstname')->willReturn('Jan');
        $order->method('getCustomerLastname')->willReturn('Kowalski');
        $order->expects(self::once())->method('setStatus')->with(AddPendingApprovalOrderStatus::STATUS_PENDING_APPROVAL);
        $store = $this->stubOrderStore($order);

        $spendLimitAttr = $this->createStub(AttributeInterface::class);
        $spendLimitAttr->method('getValue')->willReturn('100');
        $adminEmailAttr = $this->createStub(AttributeInterface::class);
        $adminEmailAttr->method('getValue')->willReturn('admin@example.com');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturnMap([
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_SPEND_LIMIT, $spendLimitAttr],
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_APPROVAL_ADMIN_EMAIL, $adminEmailAttr],
        ]);

        $this->customerRepository->expects(self::never())->method('getById');
        $this->customerRepository->expects(self::once())->method('get')->with('vip@example.com', 1)
            ->willReturn($customer);

        $this->orderResource->expects(self::once())->method('save')->with($order);

        $this->random->method('getUniqueHash')->willReturn('token123');

        $approval = $this->createMock(OrderApproval::class);
        $approval->expects(self::once())->method('setData')->with([
            'order_id' => 7,
            'admin_email' => 'admin@example.com',
            'token' => 'token123',
            'status' => OrderApproval::STATUS_PENDING,
        ]);
        $this->orderApprovalFactory->method('create')->willReturn($approval);
        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);

        $this->expectApprovalEmailSentFor($store, 'token123', 'admin@example.com');

        $this->makeObserverInstance()->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsWhenCustomerLookupFails(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(42);

        $this->customerRepository->method('getById')->willThrowException(new LocalizedException(__('missing')));
        $this->orderResource->expects(self::never())->method('save');

        $this->makeObserverInstance()->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenOrderBelowSpendLimit(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getGrandTotal')->willReturn(50.0);

        $spendLimitAttr = $this->createStub(AttributeInterface::class);
        $spendLimitAttr->method('getValue')->willReturn('100');
        $adminEmailAttr = $this->createStub(AttributeInterface::class);
        $adminEmailAttr->method('getValue')->willReturn('admin@example.com');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturnMap([
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_SPEND_LIMIT, $spendLimitAttr],
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_APPROVAL_ADMIN_EMAIL, $adminEmailAttr],
        ]);
        $this->customerRepository->method('getById')->willReturn($customer);

        $this->orderResource->expects(self::never())->method('save');

        $this->makeObserverInstance()->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteHoldsOrderAndSendsApprovalEmailWhenOverLimit(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getGrandTotal')->willReturn(500.0);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getCustomerFirstname')->willReturn('Jan');
        $order->method('getCustomerLastname')->willReturn('Kowalski');
        $order->expects(self::once())->method('setStatus')->with(AddPendingApprovalOrderStatus::STATUS_PENDING_APPROVAL);
        $store = $this->stubOrderStore($order);

        $spendLimitAttr = $this->createStub(AttributeInterface::class);
        $spendLimitAttr->method('getValue')->willReturn('100');
        $adminEmailAttr = $this->createStub(AttributeInterface::class);
        $adminEmailAttr->method('getValue')->willReturn('admin@example.com');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturnMap([
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_SPEND_LIMIT, $spendLimitAttr],
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_APPROVAL_ADMIN_EMAIL, $adminEmailAttr],
        ]);
        $this->customerRepository->method('getById')->willReturn($customer);

        $this->orderResource->expects(self::once())->method('save')->with($order);

        $this->random->method('getUniqueHash')->willReturn('token123');

        $approval = $this->createMock(OrderApproval::class);
        $approval->expects(self::once())->method('setData')->with([
            'order_id' => 7,
            'admin_email' => 'admin@example.com',
            'token' => 'token123',
            'status' => OrderApproval::STATUS_PENDING,
        ]);
        $this->orderApprovalFactory->method('create')->willReturn($approval);
        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);

        $this->expectApprovalEmailSentFor($store, 'token123', 'admin@example.com');

        $this->makeObserverInstance()->execute($this->makeEventObserver($order));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenEmailSendingFails(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getGrandTotal')->willReturn(500.0);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $spendLimitAttr = $this->createStub(AttributeInterface::class);
        $spendLimitAttr->method('getValue')->willReturn('100');
        $adminEmailAttr = $this->createStub(AttributeInterface::class);
        $adminEmailAttr->method('getValue')->willReturn('admin@example.com');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturnMap([
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_SPEND_LIMIT, $spendLimitAttr],
            [AddCustomerSpendLimitAttributes::ATTRIBUTE_APPROVAL_ADMIN_EMAIL, $adminEmailAttr],
        ]);
        $this->customerRepository->method('getById')->willReturn($customer);

        $this->random->method('getUniqueHash')->willReturn('token123');
        $this->orderApprovalFactory->method('create')->willReturn($this->createStub(OrderApproval::class));

        $this->logger->expects(self::once())->method('error');

        $this->makeObserverInstance()->execute($this->makeEventObserver($order));
    }
}
