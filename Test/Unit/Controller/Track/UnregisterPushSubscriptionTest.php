<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\UnregisterPushSubscription;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class UnregisterPushSubscriptionTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private PushSubscriptionManager $pushSubscriptionManager;
    private CustomerSession $customerSession;
    private CookieManagerInterface $cookieManager;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->pushSubscriptionManager = $this->createMock(PushSubscriptionManager::class);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->cookieManager = $this->createStub(CookieManagerInterface::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): UnregisterPushSubscription
    {
        return new UnregisterPushSubscription(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->pushSubscriptionManager,
            new VisitorIdentityResolver($this->customerSession, $this->cookieManager)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenEndpointMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['endpoint', null, '']]);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->pushSubscriptionManager->expects(self::never())->method('unregister');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteUnregistersEndpointUsingVisitorCookie(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['endpoint', null, 'https://push.example.com/1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn('visitor-1');

        $this->pushSubscriptionManager->expects(self::once())->method('unregister')
            ->with('https://push.example.com/1', null, 'visitor-1');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteUnregistersEndpointForLoggedInCustomer(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['endpoint', null, 'https://push.example.com/1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $this->cookieManager->method('getCookie')->willReturn(null);

        $this->pushSubscriptionManager->expects(self::once())->method('unregister')
            ->with('https://push.example.com/1', 42, '');
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
    public function testValidateForCsrfReturnsTrueForAnonymousVisitor(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $controller = $this->makeController();

        self::assertTrue($controller->validateForCsrf($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfRejectsMismatchedOriginForLoggedInCustomer(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturnMap([
            ['Origin', 'https://attacker.example.com'],
        ]);
        $this->request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertFalse($controller->validateForCsrf($this->request));
    }
}
