<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\WhatsApp;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Ordo\Automation\Controller\WhatsApp\Webhook;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\MessageLog\StatusDowngradeGuard;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\Collection as MessageLogCollection;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\Collection as WhatsAppTemplateCollection;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\CollectionFactory as WhatsAppTemplateCollectionFactory;
use Ordo\Automation\Model\WhatsApp\WhatsAppSignatureValidator;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;

/**
 * Uses a real WhatsAppSignatureValidator (real HMAC-SHA256, same reasoning as
 * Sms\StatusCallbackTest's real Twilio\Security\RequestValidator) so the signature-rejection
 * tests prove the controller's actual verification call site works, not just a mocked stand-in.
 */
class WebhookTest extends AbstractFrontendActionTestCase
{
    private const APP_SECRET = 'app-secret';
    private const VERIFY_TOKEN = 'verify-token';

    private RawFactory $resultRawFactory;
    private JsonFactory $resultJsonFactory;
    private Config $config;
    private MessageLogCollectionFactory $messageLogCollectionFactory;
    private MessageLogResource&\PHPUnit\Framework\MockObject\MockObject $messageLogResource;
    private WhatsAppTemplateCollectionFactory $whatsAppTemplateCollectionFactory;
    private WhatsAppTemplateResource&\PHPUnit\Framework\MockObject\MockObject $whatsAppTemplateResource;
    private LoggerInterface $logger;
    private Raw $rawResult;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultRawFactory = $this->createStub(RawFactory::class);
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getWhatsAppAppSecret')->willReturn(self::APP_SECRET);
        $this->config->method('getWhatsAppWebhookVerifyToken')->willReturn(self::VERIFY_TOKEN);
        $this->messageLogCollectionFactory = $this->createMock(MessageLogCollectionFactory::class);
        $this->messageLogResource = $this->createMock(MessageLogResource::class);
        $this->whatsAppTemplateCollectionFactory = $this->createMock(WhatsAppTemplateCollectionFactory::class);
        $this->whatsAppTemplateResource = $this->createMock(WhatsAppTemplateResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->rawResult = $this->createMock(Raw::class);
        $this->rawResult->method('setHttpResponseCode')->willReturnSelf();
        $this->rawResult->method('setContents')->willReturnSelf();
        $this->resultRawFactory->method('create')->willReturn($this->rawResult);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->jsonResult->method('setHttpResponseCode')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): Webhook
    {
        return new Webhook(
            $this->makeContext(),
            $this->resultRawFactory,
            $this->resultJsonFactory,
            $this->config,
            new WhatsAppSignatureValidator(),
            $this->messageLogCollectionFactory,
            $this->messageLogResource,
            $this->whatsAppTemplateCollectionFactory,
            $this->whatsAppTemplateResource,
            new StatusDowngradeGuard(),
            $this->logger
        );
    }

    private function signatureFor(string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, self::APP_SECRET);
    }

    private function makeMessageLog(?int $id): MessageLog
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

        return $log;
    }

    private function makeTemplate(?int $id): WhatsAppTemplate
    {
        $resource = $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $template = new WhatsAppTemplate(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $resource
        );
        if ($id !== null) {
            $template->setId($id);
        }

        return $template;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetVerificationSucceedsAndEchoesChallenge(): void
    {
        $controller = $this->makeController();
        $this->request->method('isGet')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['hub_mode', null, 'subscribe'],
            ['hub_verify_token', null, self::VERIFY_TOKEN],
            ['hub_challenge', null, 'challenge-123'],
        ]);

        $this->rawResult->expects(self::never())->method('setHttpResponseCode');
        $this->rawResult->expects(self::once())->method('setContents')->with('challenge-123');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetVerificationFailsWhenTokenDoesNotMatch(): void
    {
        $controller = $this->makeController();
        $this->request->method('isGet')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['hub_mode', null, 'subscribe'],
            ['hub_verify_token', null, 'wrong-token'],
            ['hub_challenge', null, 'challenge-123'],
        ]);

        $this->rawResult->expects(self::once())->method('setHttpResponseCode')->with(403);
        $this->rawResult->expects(self::once())->method('setContents')->with('');
        $this->logger->expects(self::once())->method('error');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetVerificationFailsWhenModeIsNotSubscribe(): void
    {
        $controller = $this->makeController();
        $this->request->method('isGet')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['hub_mode', null, 'unsubscribe'],
            ['hub_verify_token', null, self::VERIFY_TOKEN],
            ['hub_challenge', null, 'challenge-123'],
        ]);

        $this->rawResult->expects(self::once())->method('setHttpResponseCode')->with(403);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostRejectsInvalidSignatureWithoutTouchingTheDatabase(): void
    {
        $controller = $this->makeController();
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn('sha256=' . str_repeat('0', 64));
        $this->request->method('getContent')->willReturn('{"entry":[]}');

        $this->messageLogCollectionFactory->expects(self::never())->method('create');
        $this->whatsAppTemplateCollectionFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(403);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithValidSignatureButInvalidJsonReturnsInvalidPayload(): void
    {
        $controller = $this->makeController();
        $rawBody = 'not json';
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_payload']);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithKnownMessageStatusUpdateSavesTheLogRow(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [
                            ['id' => 'wamid.123', 'status' => 'delivered'],
                        ],
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $log = $this->makeMessageLog(7);
        $collection = $this->createMock(MessageLogCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')
            ->with('provider_message_id', 'wamid.123')->willReturnSelf();
        $collection->expects(self::once())->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();

        self::assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
        self::assertNull($log->getErrorCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithMessageStatusIncludingErrorCodeSavesIt(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [
                            ['id' => 'wamid.123', 'status' => 'failed', 'errors' => [['code' => 131047]]],
                        ],
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $log = $this->makeMessageLog(7);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);

        $controller->execute();

        self::assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
        self::assertSame('131047', $log->getErrorCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithRedeliveredEarlierStatusDoesNotRegressAnAlreadyFinalLogRow(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [
                            ['id' => 'wamid.123', 'status' => 'sent'],
                        ],
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $log = $this->makeMessageLog(7);
        $log->setStatus(MessageLog::STATUS_FAILED);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::never())->method('save');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();

        self::assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithUnknownMessageIdDoesNotSave(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [
                            ['id' => 'wamid.unknown', 'status' => 'sent'],
                        ],
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $log = $this->makeMessageLog(null);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('info');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithUnrecognizedMessageStatusIsIgnored(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [
                            ['id' => 'wamid.123', 'status' => 'read'],
                        ],
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $this->messageLogCollectionFactory->expects(self::never())->method('create');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithKnownTemplateStatusUpdateSavesTheTemplate(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'message_template_id' => 'meta-template-123',
                        'event' => 'approved',
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $template = $this->makeTemplate(3);
        $collection = $this->createMock(WhatsAppTemplateCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')
            ->with('meta_template_id', 'meta-template-123')->willReturnSelf();
        $collection->expects(self::once())->method('getFirstItem')->willReturn($template);
        $this->whatsAppTemplateCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $this->whatsAppTemplateResource->expects(self::once())->method('save')->with($template);

        $controller->execute();

        self::assertSame(WhatsAppTemplate::STATUS_APPROVED, $template->getStatus());
        self::assertNull($template->getRejectionReason());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithRejectedTemplateStatusSavesTheReason(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'message_template_id' => 'meta-template-123',
                        'event' => 'rejected',
                        'reason' => 'INVALID_FORMAT',
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $template = $this->makeTemplate(3);
        $collection = $this->createStub(WhatsAppTemplateCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($template);
        $this->whatsAppTemplateCollectionFactory->method('create')->willReturn($collection);

        $this->whatsAppTemplateResource->expects(self::once())->method('save')->with($template);

        $controller->execute();

        self::assertSame(WhatsAppTemplate::STATUS_REJECTED, $template->getStatus());
        self::assertSame('INVALID_FORMAT', $template->getRejectionReason());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithUnknownTemplateIdDoesNotSave(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'message_template_id' => 'meta-template-unknown',
                        'event' => 'approved',
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $template = $this->makeTemplate(null);
        $collection = $this->createStub(WhatsAppTemplateCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($template);
        $this->whatsAppTemplateCollectionFactory->method('create')->willReturn($collection);

        $this->whatsAppTemplateResource->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('info');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostWithUnmappedTemplateStatusEventIsIgnored(): void
    {
        $controller = $this->makeController();
        $rawBody = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'message_template_id' => 'meta-template-1',
                        'event' => 'some_future_event_we_dont_map_yet',
                    ],
                ]],
            ]],
        ]);
        $this->request->method('isGet')->willReturn(false);
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $this->whatsAppTemplateCollectionFactory->expects(self::never())->method('create');

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
