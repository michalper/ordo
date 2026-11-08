<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\DismissNotification;
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\NotificationFactory;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DismissNotificationTest extends TestCase
{
    private NotificationFactory $notificationFactory;
    private NotificationResource $notificationResource;

    protected function setUp(): void
    {
        $this->notificationFactory = $this->createMock(NotificationFactory::class);
        $this->notificationResource = $this->createMock(NotificationResource::class);
    }

    private function makeResolver(): DismissNotification
    {
        return new DismissNotification(
            $this->notificationFactory,
            $this->notificationResource,
            new CustomerIdResolver()
        );
    }

    private function resolve(DismissNotification $resolver, array $args, int $userId = 0): mixed
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn($userId);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            $args
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenNotificationIdMissing(): void
    {
        $resolver = $this->makeResolver();

        $this->notificationFactory->expects(self::never())->method('create');

        self::assertSame(['ok' => false], $this->resolve($resolver, []));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenNotificationDoesNotExist(): void
    {
        $resolver = $this->makeResolver();

        $notification = $this->createStub(Notification::class);
        $notification->method('getId')->willReturn(null);
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::once())->method('load')->with($notification, 5);
        $this->notificationResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['notificationId' => 5, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenNotificationBelongsToSomeoneElse(): void
    {
        $resolver = $this->makeResolver();

        $notification = $this->createMock(Notification::class);
        $notification->method('getId')->willReturn(5);
        $notification->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(false);
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::never())->method('save');

        self::assertSame(
            ['ok' => false],
            $this->resolve($resolver, ['notificationId' => 5, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMarksReadAndReturnsTrueWhenOwnedByVisitor(): void
    {
        $resolver = $this->makeResolver();

        $notification = $this->createMock(Notification::class);
        $notification->method('getId')->willReturn(5);
        $notification->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(true);
        $notification->expects(self::once())->method('setReadAt')->with(self::callback('is_string'));
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::once())->method('save')->with($notification);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['notificationId' => 5, 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMarksReadAndReturnsTrueWhenOwnedByLoggedInCustomer(): void
    {
        $resolver = $this->makeResolver();

        $notification = $this->createMock(Notification::class);
        $notification->method('getId')->willReturn(5);
        $notification->expects(self::once())->method('belongsTo')->with(42, '')->willReturn(true);
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::once())->method('save')->with($notification);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['notificationId' => 5], userId: 42)
        );
    }
}
