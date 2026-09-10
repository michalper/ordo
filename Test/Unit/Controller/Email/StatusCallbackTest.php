<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Email;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Email\StatusCallback;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Email\SendGridSignatureValidator;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\MessageLogEventWriter;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\Collection as MessageLogCollection;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;

class StatusCallbackTest extends AbstractFrontendActionTestCase
{
    private const string VERIFICATION_KEY = 'a-verification-key';

    private JsonFactory $resultJsonFactory;
    private Config $config;
    private SendGridSignatureValidator&\PHPUnit\Framework\MockObject\MockObject $signatureValidator;
    private MessageLogCollectionFactory $messageLogCollectionFactory;
    private MessageLogResource&\PHPUnit\Framework\MockObject\MockObject $messageLogResource;
    private MessageLogEventWriter&\PHPUnit\Framework\MockObject\MockObject $messageLogEventWriter;
    private ConsentManager&\PHPUnit\Framework\MockObject\MockObject $consentManager;
    private LoggerInterface $logger;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getSendGridWebhookVerificationKey')->willReturn(self::VERIFICATION_KEY);
        $this->signatureValidator = $this->createMock(SendGridSignatureValidator::class);
        $this->messageLogCollectionFactory = $this->createMock(MessageLogCollectionFactory::class);
        $this->messageLogResource = $this->createMock(MessageLogResource::class);
        $this->messageLogEventWriter = $this->createMock(MessageLogEventWriter::class);
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->jsonResult->method('setHttpResponseCode')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): StatusCallback
    {
        return new StatusCallback(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->config,
            $this->signatureValidator,
            $this->messageLogCollectionFactory,
            $this->messageLogResource,
            $this->messageLogEventWriter,
            $this->consentManager,
            $this->logger
        );
    }

    private function makeMessageLog(?int $id, ?int $customerId = null): MessageLog
    {
        $resource = $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $log = new MessageLog(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $resource
        );
        if ($id !== null) {
            $log->setId($id);
        }
        if ($customerId !== null) {
            $log->setCustomerId($customerId);
        }

        return $log;
    }

    private function stubHeaders(string $signature, string $timestamp): void
    {
        $this->request->method('getHeader')->willReturnMap([
            ['X-Twilio-Email-Event-Webhook-Signature', $signature],
            ['X-Twilio-Email-Event-Webhook-Timestamp', $timestamp],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInvalidSignatureIsRejectedWithoutTouchingTheDatabase(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('forged-signature', '1700000000');
        $this->request->method('getContent')->willReturn('[]');
        $this->signatureValidator->method('isValid')->willReturn(false);

        $this->messageLogCollectionFactory->expects(self::never())->method('create');
        $this->messageLogResource->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('error');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(403);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMissingSignatureHeaderIsRejected(): void
    {
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturn(false);
        $this->request->method('getContent')->willReturn('[]');
        $this->signatureValidator->expects(self::never())->method('isValid');

        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(403);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithDeliveredEventUpdatesTheLogRow(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'delivered', 'smtp-id' => '<abc@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->expects(self::once())->method('isValid')
            ->with(self::VERIFICATION_KEY, '1700000000', $body, 'real-signature')->willReturn(true);

        $log = $this->makeMessageLog(7);
        $collection = $this->createMock(MessageLogCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')
            ->with('provider_message_id', '<abc@example.com>')->willReturnSelf();
        $collection->expects(self::once())->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();

        self::assertSame('delivered', $log->getStatus());
        self::assertNull($log->getErrorCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithBounceEventRecordsTheReasonAsErrorCode(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([
            ['event' => 'bounce', 'smtp-id' => '<abc@example.com>', 'reason' => '550 mailbox unavailable'],
        ]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);

        $controller->execute();

        self::assertSame('undelivered', $log->getStatus());
        self::assertSame('550 mailbox unavailable', $log->getErrorCode());
    }

    /**
     * Regression test for a real bug a code audit found: unsubscribe/spamreport/
     * group_unsubscribe used to fall into the "unhandled event type, silently skipped" bucket -
     * a one-click unsubscribe or spam complaint never reached ConsentManager, so send_email kept
     * mailing someone who had, in every real sense, opted out.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithUnsubscribeEventRecordsConsentOptOut(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'unsubscribe', 'smtp-id' => '<abc@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7, 42);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->consentManager->expects(self::once())->method('setConsent')
            ->with(42, ConsentChannel::Email, false, 'sendgrid_unsubscribe');

        $controller->execute();

        self::assertSame('opted_out', $log->getStatus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithSpamreportEventRecordsConsentOptOut(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'spamreport', 'smtp-id' => '<abc@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7, 42);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->consentManager->expects(self::once())->method('setConsent')
            ->with(42, ConsentChannel::Email, false, 'sendgrid_spamreport');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithUnsubscribeEventButNoKnownCustomerSkipsConsentUpdate(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'unsubscribe', 'smtp-id' => '<abc@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->consentManager->expects(self::never())->method('setConsent');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithDeliveredEventDoesNotTouchConsent(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'delivered', 'smtp-id' => '<abc@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7, 42);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->method('save');
        $this->consentManager->expects(self::never())->method('setConsent');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithUnknownSmtpIdSkipsWithoutSaving(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'delivered', 'smtp-id' => '<unknown@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(null);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithUnhandledEventTypeIsSkipped(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'processed', 'smtp-id' => '<abc@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $this->messageLogCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    /**
     * Regression test for the funnel-analytics feature: "open"/"click" must write a new
     * ordo_message_log_event row via MessageLogEventWriter, NOT touch ordo_message_log.status —
     * overwriting status would destroy an earlier "delivered" signal the funnel still needs.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithOpenEventRecordsEventWithoutTouchingStatus(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([['event' => 'open', 'smtp-id' => '<abc@example.com>']]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::never())->method('save');
        $this->messageLogEventWriter->expects(self::once())->method('recordOpened')->with(7);

        $controller->execute();

        self::assertSame('', $log->getStatus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithClickEventRecordsEventWithUrl(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([[
            'event' => 'click',
            'smtp-id' => '<abc@example.com>',
            'url' => 'https://example.com/product',
        ]]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::never())->method('save');
        $this->messageLogEventWriter->expects(self::once())->method('recordClicked')
            ->with(7, 'https://example.com/product');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithDeliveredThenOpenLeavesStatusAsDelivered(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $body = json_encode([
            ['event' => 'delivered', 'smtp-id' => '<abc@example.com>'],
            ['event' => 'open', 'smtp-id' => '<abc@example.com>'],
        ]);
        $this->request->method('getContent')->willReturn($body);
        $this->signatureValidator->method('isValid')->willReturn(true);

        $log = $this->makeMessageLog(7);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->messageLogEventWriter->expects(self::once())->method('recordOpened')->with(7);

        $controller->execute();

        self::assertSame('delivered', $log->getStatus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithInvalidJsonPayloadReturnsInvalidPayload(): void
    {
        $controller = $this->makeController();
        $this->stubHeaders('real-signature', '1700000000');
        $this->request->method('getContent')->willReturn('not json');
        $this->signatureValidator->method('isValid')->willReturn(true);

        $this->messageLogCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_payload']);

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
