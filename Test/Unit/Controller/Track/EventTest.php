<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\Event;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Model\VisitorEventLogger;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class EventTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private VisitorEventLogger $visitorEventLogger;
    private CustomerSession $customerSession;
    private CookieManagerInterface $cookieManager;
    private Config $config;
    private Json $jsonResult;
    private EventManagerInterface $eventManager;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->visitorEventLogger = $this->createMock(VisitorEventLogger::class);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->cookieManager = $this->createStub(CookieManagerInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): Event
    {
        return new Event(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->visitorEventLogger,
            new VisitorIdentityResolver($this->customerSession, $this->cookieManager),
            $this->config,
            $this->eventManager
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsDisabledWhenTrackingOff(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'tracking_disabled']);
        $this->visitorEventLogger->expects(self::never())->method('log');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenVisitorIdMissing(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, ''],
            ['event_type', null, 'page_view'],
        ]);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->visitorEventLogger->expects(self::never())->method('log');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenEventTypeNotAllowed(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'not_allowed'],
        ]);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_payload']);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsEventForAnonymousVisitor(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'product_view'],
            ['event_key', null, '15'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->visitorEventLogger->expects(self::once())->method('log')->with('v1', 'product_view', '15', null);
        $this->eventManager->expects(self::never())->method('dispatch');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    /**
     * exit_intent (docs/adr/0037) is the one event type that also fires a Magento event, live -
     * every other allowed type above only ever feeds VisitorAggregator's threshold-based tagging.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesExitIntentEventForAnonymousVisitor(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'exit_intent'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->visitorEventLogger->expects(self::once())->method('log')->with('v1', 'exit_intent', null, null);
        $this->eventManager->expects(self::once())->method('dispatch')
            ->with('ordo_visitor_exit_intent', ['visitor_id' => 'v1']);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    /**
     * search_no_results (docs/adr/0076) is also a live trigger, same reasoning as exit_intent -
     * but unlike that one, customer_id rides along when the request is anonymous-free.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesSearchNoResultsEventForAnonymousVisitor(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'search_no_results'],
            ['event_key', null, 'running shoes'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->visitorEventLogger->expects(self::once())->method('log')
            ->with('v1', 'search_no_results', 'running shoes', null);
        $this->eventManager->expects(self::once())->method('dispatch')
            ->with('ordo_visitor_search_no_results', ['visitor_id' => 'v1', 'customer_id' => null]);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesSearchNoResultsEventWithCustomerIdForLoggedInCustomer(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'search_no_results'],
            ['event_key', null, 'running shoes'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $this->eventManager->expects(self::once())->method('dispatch')
            ->with('ordo_visitor_search_no_results', ['visitor_id' => 'v1', 'customer_id' => 42]);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsElementClickedEvent(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'element_clicked'],
            ['event_key', null, 'newsletter-signup'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->visitorEventLogger->expects(self::once())->method('log')
            ->with('v1', 'element_clicked', 'newsletter-signup', null);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    /**
     * checkout_start (docs/adr/0031) needs no event_key, unlike product_view/category_view -
     * there's nothing to identify about "checkout started" beyond the fact itself.
     */
    /**
     * utm_source/utm_campaign (docs/adr/0085) are automatic, like page_view - tracker.js sends
     * these itself from the landing URL's own query string, no theme integration needed. Unlike
     * exit_intent/search_no_results, these feed VisitorAggregator's threshold-based tagging
     * only (threshold hardcoded to 1 there) - no live Magento event to dispatch.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsUtmSourceEvent(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'utm_source'],
            ['event_key', null, 'google'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->visitorEventLogger->expects(self::once())->method('log')->with('v1', 'utm_source', 'google', null);
        $this->eventManager->expects(self::never())->method('dispatch');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsUtmCampaignEvent(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'utm_campaign'],
            ['event_key', null, 'summer_sale'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->visitorEventLogger->expects(self::once())->method('log')->with('v1', 'utm_campaign', 'summer_sale', null);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsCheckoutStartEvent(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'checkout_start'],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->visitorEventLogger->expects(self::once())->method('log')
            ->with('v1', 'checkout_start', null, null);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsEventForLoggedInCustomer(): void
    {
        $controller = $this->makeController();
        $this->config->method('isTrackingEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'v1'],
            ['event_type', null, 'category_view'],
            ['event_key', null, str_repeat('x', 300)],
        ]);

        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $this->visitorEventLogger->expects(self::once())->method('log')
            ->with('v1', 'category_view', str_repeat('x', 255), 42);

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

    /**
     * Regression: validateForCsrf() used to unconditionally return true, even for a logged-in
     * request - a forged cross-site POST could bind an attacker-chosen event_type/event_key to
     * a real customer_id and, once VisitorAggregator's threshold is crossed, tag that customer's
     * account. Now checked against Origin/Referer the same way every other Controller\Track\*
     * endpoint already is.
     */
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

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfAcceptsMatchingOriginForLoggedInCustomer(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturnMap([
            ['Origin', 'https://shop.example.com'],
        ]);
        $this->request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
