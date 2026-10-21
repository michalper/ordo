<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\DismissNotification;
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\NotificationFactory;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class DismissNotificationTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private NotificationFactory $notificationFactory;
    private NotificationResource $notificationResource;
    private CustomerSession $customerSession;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->notificationFactory = $this->createMock(NotificationFactory::class);
        $this->notificationResource = $this->createMock(NotificationResource::class);
        $this->customerSession = $this->createStub(CustomerSession::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): DismissNotification
    {
        return new DismissNotification(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->notificationFactory,
            $this->notificationResource,
            // A real resolver over the stubbed session, so identity still flows from the same
            // request params and session state the controller used to read inline.
            new VisitorIdentityResolver($this->customerSession, $this->createStub(CookieManagerInterface::class))
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenNotificationIdMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['notification_id', null]]);

        $this->notificationFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenNotificationDoesNotExist(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['notification_id', 5],
            ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $notification = $this->createStub(Notification::class);
        $notification->method('getId')->willReturn(null);
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::once())->method('load')->with($notification, 5);
        $this->notificationResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenNotificationBelongsToSomeoneElse(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['notification_id', 5],
            ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $notification = $this->createMock(Notification::class);
        $notification->method('getId')->willReturn(5);
        // The ownership rule itself lives in VisitorIdentityFieldsTrait::belongsTo() (covered in
        // Test\Unit\Model\NotificationTest); here only that the controller honors it — and feeds
        // it the resolver-derived identity, never a client-supplied customer_id.
        $notification->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(false);
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteMarksReadAndReturnsTrueWhenOwnedByVisitor(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['notification_id', 5],
            ['visitor_id', 'v1'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $notification = $this->createMock(Notification::class);
        $notification->method('getId')->willReturn(5);
        $notification->expects(self::once())->method('belongsTo')->with(null, 'v1')->willReturn(true);
        $notification->expects(self::once())->method('setReadAt')->with(self::callback('is_string'));
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::once())->method('save')->with($notification);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteMarksReadAndReturnsTrueWhenOwnedByLoggedInCustomer(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['notification_id', 5],
            ['visitor_id', ''],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $notification = $this->createMock(Notification::class);
        $notification->method('getId')->willReturn(5);
        $notification->expects(self::once())->method('belongsTo')->with(42, '')->willReturn(true);
        $notification->expects(self::once())->method('setReadAt');
        $this->notificationFactory->method('create')->willReturn($notification);

        $this->notificationResource->expects(self::once())->method('save')->with($notification);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrue(): void
    {
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
