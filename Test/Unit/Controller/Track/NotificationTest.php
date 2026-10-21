<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\Notification as NotificationController;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\ResourceModel\Notification\Collection as NotificationCollection;
use Ordo\Automation\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class NotificationTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private NotificationCollectionFactory $notificationCollectionFactory;
    private CustomerSession $customerSession;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->notificationCollectionFactory = $this->createMock(NotificationCollectionFactory::class);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->config = $this->createStub(Config::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): NotificationController
    {
        return new NotificationController(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->notificationCollectionFactory,
            // A real resolver over the stubbed session: the tests still drive identity through
            // the same request params and session state the controller used to read inline.
            new VisitorIdentityResolver($this->customerSession, $this->createStub(CookieManagerInterface::class)),
            $this->config
        );
    }

    private function makeCollection(array $notifications): NotificationCollection
    {
        $collection = $this->createStub(NotificationCollection::class);
        $collection->method('addTargetFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($notifications));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEmptyListWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNotificationEnabled')->willReturn(false);

        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['notifications' => []]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEmptyListWhenNoIdentifierGiven(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNotificationEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', '']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->notificationCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['notifications' => []]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEveryUnreadNotificationForTarget(): void
    {
        $controller = $this->makeController();
        $this->config->method('isNotificationEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $notification = $this->createStub(Notification::class);
        $notification->method('getId')->willReturn(5);
        $notification->method('getHeadline')->willReturn('Hello!');
        $notification->method('getBody')->willReturn('Come back soon');
        $notification->method('getCtaLabel')->willReturn('Shop now');
        $notification->method('getCtaUrl')->willReturn('https://example.test/sale');

        $this->notificationCollectionFactory->method('create')->willReturn($this->makeCollection([$notification]));

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'notifications' => [
                [
                    'id' => 5,
                    'headline' => 'Hello!',
                    'body' => 'Come back soon',
                    'cta_label' => 'Shop now',
                    'cta_url' => 'https://example.test/sale',
                ],
            ],
        ]);

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
