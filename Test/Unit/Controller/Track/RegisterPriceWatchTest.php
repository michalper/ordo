<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\RegisterPriceWatch;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RegisterPriceWatchTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private PriceWatchSubscriptionManager $priceWatchSubscriptionManager;
    private ProductRepositoryInterface $productRepository;
    private CustomerSession $customerSession;
    private CookieManagerInterface $cookieManager;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->priceWatchSubscriptionManager = $this->createMock(PriceWatchSubscriptionManager::class);
        $this->productRepository = $this->createStub(ProductRepositoryInterface::class);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->cookieManager = $this->createStub(CookieManagerInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPriceWatchEnabled')->willReturn(true);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): RegisterPriceWatch
    {
        return new RegisterPriceWatch(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->priceWatchSubscriptionManager,
            $this->productRepository,
            $this->customerSession,
            $this->cookieManager,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsDisabledWhenPriceWatchOff(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPriceWatchEnabled')->willReturn(false);
        $controller = $this->makeController();

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'price_watch_disabled']);
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenProductIdMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['product_id', null, ''],
            ['watch_type', null, 'price_drop'],
        ]);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenWatchTypeUnknown(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['product_id', null, '10'],
            ['watch_type', null, 'not_a_real_type'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn('visitor-1');

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenAnonymousWithNoVisitorCookie(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['product_id', null, '10'],
            ['watch_type', null, 'price_drop'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn(null);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidProductWhenProductDoesNotExist(): void
    {
        $this->productRepository = $this->createStub(ProductRepositoryInterface::class);
        $this->productRepository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['product_id', null, '999'],
            ['watch_type', null, 'price_drop'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn('visitor-1');

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_product']);
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRegistersAnonymousWatchUsingVisitorCookie(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['product_id', null, '10'],
            ['watch_type', null, 'back_in_stock'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn('visitor-1');

        $this->priceWatchSubscriptionManager->expects(self::once())->method('register')
            ->with(10, PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK, null, 'visitor-1');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRegistersLoggedInCustomerWatch(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['product_id', null, '10'],
            ['watch_type', null, 'price_drop'],
        ]);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $this->cookieManager->method('getCookie')->willReturn(null);

        $this->priceWatchSubscriptionManager->expects(self::once())->method('register')
            ->with(10, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 42, null);

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
}
