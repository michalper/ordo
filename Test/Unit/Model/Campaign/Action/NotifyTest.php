<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\ContextTargetResolver;
use Ordo\Automation\Model\Campaign\Action\Notify;
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\NotificationFactory;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class NotifyTest extends TestCase
{
    private NotificationFactory&\PHPUnit\Framework\MockObject\MockObject $notificationFactory;
    private NotificationResource&\PHPUnit\Framework\MockObject\MockObject $notificationResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private Notify $action;

    protected function setUp(): void
    {
        $this->notificationFactory = $this->createMock(NotificationFactory::class);
        $this->notificationResource = $this->createMock(NotificationResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->action = new Notify(
            $this->notificationFactory,
            $this->notificationResource,
            new ContextTargetResolver(),
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNotificationForCustomer(): void
    {
        $notification = $this->createMock(Notification::class);
        $notification->expects(self::once())->method('setCustomerId')->with(42);
        $notification->expects(self::once())->method('setVisitorId')->with(null);
        $notification->expects(self::once())->method('setHeadline')->with('Hello!');
        $notification->expects(self::once())->method('setBody')->with('Come back soon');
        $notification->expects(self::once())->method('setCtaLabel')->with('Shop now');
        $notification->expects(self::once())->method('setCtaUrl')->with('https://example.test/sale');
        $this->notificationFactory->method('create')->willReturn($notification);
        $this->notificationResource->expects(self::once())->method('save')->with($notification);

        $context = ['customer_id' => 42];
        $this->action->execute($context, [
            'headline' => 'Hello!',
            'body' => 'Come back soon',
            'cta_label' => 'Shop now',
            'cta_url' => 'https://example.test/sale',
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNotificationForAnonymousVisitor(): void
    {
        $notification = $this->createMock(Notification::class);
        $notification->expects(self::once())->method('setCustomerId')->with(null);
        $notification->expects(self::once())->method('setVisitorId')->with('v1');
        $this->notificationFactory->method('create')->willReturn($notification);
        $this->notificationResource->expects(self::once())->method('save')->with($notification);

        $context = ['visitor_id' => 'v1'];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLeavesOptionalParamsNullWhenBlank(): void
    {
        $notification = $this->createMock(Notification::class);
        $notification->expects(self::once())->method('setBody')->with(null);
        $notification->expects(self::once())->method('setCtaLabel')->with(null);
        $notification->expects(self::once())->method('setCtaUrl')->with(null);
        $this->notificationFactory->method('create')->willReturn($notification);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenNoIdentifierInContext(): void
    {
        $this->notificationFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenHeadlineMissing(): void
    {
        $this->notificationFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsHeadlineBodyAndCta(): void
    {
        self::assertSame(
            ['headline', 'body', 'cta_label', 'cta_url'],
            array_column($this->action->getParamFields(), 'name')
        );
    }
}
