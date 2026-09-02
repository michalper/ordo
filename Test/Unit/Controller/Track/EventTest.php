<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Track\Event;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\VisitorEventLogger;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class EventTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private VisitorEventLogger $visitorEventLogger;
    private CustomerSession $customerSession;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->visitorEventLogger = $this->createMock(VisitorEventLogger::class);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->config = $this->createStub(Config::class);

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
            $this->customerSession,
            $this->config
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
    public function testValidateForCsrfReturnsTrue(): void
    {
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
