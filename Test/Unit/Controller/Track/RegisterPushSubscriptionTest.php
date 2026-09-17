<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\RegisterPushSubscription;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Push\PushEndpointValidator;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RegisterPushSubscriptionTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private PushSubscriptionManager $pushSubscriptionManager;
    private PushEndpointValidator $pushEndpointValidator;
    private CustomerSession $customerSession;
    private CookieManagerInterface $cookieManager;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->pushSubscriptionManager = $this->createMock(PushSubscriptionManager::class);
        $this->pushEndpointValidator = $this->createStub(PushEndpointValidator::class);
        $this->pushEndpointValidator->method('isAllowed')->willReturn(true);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->cookieManager = $this->createStub(CookieManagerInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPushEnabled')->willReturn(true);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): RegisterPushSubscription
    {
        return new RegisterPushSubscription(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->pushSubscriptionManager,
            $this->pushEndpointValidator,
            new VisitorIdentityResolver($this->customerSession, $this->cookieManager),
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsDisabledWhenPushOff(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPushEnabled')->willReturn(false);
        $controller = $this->makeController();

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'push_disabled']);
        $this->pushSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenEndpointMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['endpoint', null, ''],
            ['p256dh', null, 'key'],
            ['auth', null, 'auth'],
        ]);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->pushSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenAnonymousWithNoVisitorCookie(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['endpoint', null, 'https://push.example.com/1'],
            ['p256dh', null, 'key'],
            ['auth', null, 'auth'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn(null);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->pushSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidEndpointWhenValidatorRejectsIt(): void
    {
        $this->pushEndpointValidator = $this->createStub(PushEndpointValidator::class);
        $this->pushEndpointValidator->method('isAllowed')->willReturn(false);
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['endpoint', null, 'http://169.254.169.254/latest/meta-data/'],
            ['p256dh', null, 'key'],
            ['auth', null, 'auth'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn('visitor-1');

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_endpoint']);
        $this->pushSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRegistersAnonymousSubscriptionUsingVisitorCookie(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['endpoint', null, 'https://push.example.com/1'],
            ['p256dh', null, 'p256dh-key'],
            ['auth', null, 'auth-key'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn('visitor-1');

        $this->pushSubscriptionManager->expects(self::once())->method('register')
            ->with('https://push.example.com/1', 'p256dh-key', 'auth-key', null, 'visitor-1');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRegistersLoggedInCustomerSubscription(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['endpoint', null, 'https://push.example.com/1'],
            ['p256dh', null, 'p256dh-key'],
            ['auth', null, 'auth-key'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $this->cookieManager->method('getCookie')->willReturn(null);

        $this->pushSubscriptionManager->expects(self::once())->method('register')
            ->with('https://push.example.com/1', 'p256dh-key', 'auth-key', 42, null);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrueForAnonymousVisitor(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $controller = $this->makeController();

        self::assertTrue($controller->validateForCsrf($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfAcceptsMatchingOrigin(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturnMap([
            ['Origin', 'https://shop.example.com'],
        ]);
        $this->request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertTrue($controller->validateForCsrf($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfRejectsMismatchedOrigin(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturnMap([
            ['Origin', 'https://attacker.example.com'],
        ]);
        $this->request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertFalse($controller->validateForCsrf($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfFallsBackToRefererWhenOriginMissing(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturnMap([
            ['Origin', false],
            ['Referer', 'https://shop.example.com/some/page'],
        ]);
        $this->request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertTrue($controller->validateForCsrf($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfRejectsWhenNeitherOriginNorRefererPresent(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturn(false);

        self::assertFalse($controller->validateForCsrf($this->request));
    }
}
