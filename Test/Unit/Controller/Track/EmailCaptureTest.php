<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Ordo\Automation\Controller\Track\EmailCapture;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\VisitorTagManager;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use RuntimeException;

class EmailCaptureTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private Config $config;
    private SubscriberFactory $subscriberFactory;
    private Subscriber $subscriber;
    private VisitorTagManager $visitorTagManager;
    private LoggerInterface $logger;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->config = $this->createStub(Config::class);
        $this->subscriber = $this->createMock(Subscriber::class);
        $this->subscriberFactory = $this->createStub(SubscriberFactory::class);
        $this->subscriberFactory->method('create')->willReturn($this->subscriber);
        $this->visitorTagManager = $this->createMock(VisitorTagManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): EmailCapture
    {
        return new EmailCapture(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->config,
            $this->subscriberFactory,
            $this->visitorTagManager,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsDisabledReasonWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isEmailCaptureEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'disabled']);
        $this->subscriber->expects(self::never())->method('subscribe');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenEmailIsMalformed(): void
    {
        $controller = $this->makeController();
        $this->config->method('isEmailCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'visitor-1'],
            ['email', null, 'not-an-email'],
        ]);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->subscriber->expects(self::never())->method('subscribe');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsInvalidPayloadWhenVisitorIdIsMissing(): void
    {
        $controller = $this->makeController();
        $this->config->method('isEmailCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, null],
            ['email', null, 'jane@example.com'],
        ]);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_payload']);
        $this->subscriber->expects(self::never())->method('subscribe');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSubscribesAndTagsTheVisitorOnSuccess(): void
    {
        $controller = $this->makeController();
        $this->config->method('isEmailCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'visitor-1'],
            ['email', null, ' jane@example.com '],
        ]);

        $this->subscriber->expects(self::once())->method('subscribe')->with('jane@example.com');
        $this->visitorTagManager->expects(self::once())->method('addTag')
            ->with('visitor-1', 'newsletter_signup');

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSubscribeFailedWhenSubscriberThrows(): void
    {
        $controller = $this->makeController();
        $this->config->method('isEmailCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['visitor_id', null, 'visitor-1'],
            ['email', null, 'jane@example.com'],
        ]);
        $this->subscriber->method('subscribe')->willThrowException(new RuntimeException('boom'));

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'subscribe_failed']);
        $this->visitorTagManager->expects(self::never())->method('addTag');

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
