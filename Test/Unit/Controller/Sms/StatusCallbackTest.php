<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Sms;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Sms\StatusCallback;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\Collection as MessageLogCollection;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Ordo\Automation\Model\Sms\CallbackUrlBuilder;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Twilio\Security\RequestValidator;

/**
 * The single most important test in this file: an invalid/forged X-Twilio-Signature must be
 * rejected without ever touching ordo_message_log — that check is the actual trust boundary for
 * this public, unauthenticated endpoint. The "valid" cases use a real Twilio\Security\
 * RequestValidator (the same class the controller itself uses) to compute a genuinely correct
 * signature for the given URL/params/auth-token, rather than a hand-faked one, so these tests
 * prove the controller's verification logic actually accepts what Twilio would really send.
 */
class StatusCallbackTest extends AbstractFrontendActionTestCase
{
    private const AUTH_TOKEN = 'secret-token';
    private const CALLBACK_URL = 'https://example.com/ordo/sms/statuscallback';

    private JsonFactory $resultJsonFactory;
    private Config $config;
    private CallbackUrlBuilder $callbackUrlBuilder;
    private MessageLogCollectionFactory $messageLogCollectionFactory;
    private MessageLogResource&\PHPUnit\Framework\MockObject\MockObject $messageLogResource;
    private LoggerInterface $logger;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getTwilioAuthToken')->willReturn(self::AUTH_TOKEN);
        $this->callbackUrlBuilder = $this->createStub(CallbackUrlBuilder::class);
        $this->callbackUrlBuilder->method('getSmsStatusCallbackUrl')->willReturn(self::CALLBACK_URL);
        $this->messageLogCollectionFactory = $this->createMock(MessageLogCollectionFactory::class);
        $this->messageLogResource = $this->createMock(MessageLogResource::class);
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
            $this->callbackUrlBuilder,
            $this->messageLogCollectionFactory,
            $this->messageLogResource,
            $this->logger
        );
    }

    private function validSignatureFor(array $postParams): string
    {
        return (new RequestValidator(self::AUTH_TOKEN))->computeSignature(self::CALLBACK_URL, $postParams);
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

    #[AllowMockObjectsWithoutExpectations]
    public function testInvalidSignatureIsRejectedWithoutTouchingTheDatabase(): void
    {
        $controller = $this->makeController();
        $postParams = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $this->request->method('getHeader')->willReturnMap([['X-Twilio-Signature', 'totally-forged-signature']]);
        $this->request->method('getPostValue')->willReturn($postParams);

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
        $this->request->method('getPostValue')->willReturn(['MessageSid' => 'SM123', 'MessageStatus' => 'delivered']);

        $this->messageLogCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(403);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithKnownMessageSidUpdatesTheLogRow(): void
    {
        $controller = $this->makeController();
        $postParams = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
        $this->request->method('getHeader')
            ->willReturnMap([['X-Twilio-Signature', $this->validSignatureFor($postParams)]]);
        $this->request->method('getPostValue')->willReturn($postParams);

        $log = $this->makeMessageLog(7);
        $collection = $this->createMock(MessageLogCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')->with('provider_message_id', 'SM123')->willReturnSelf();
        $collection->expects(self::once())->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();

        self::assertSame('delivered', $log->getStatus());
        self::assertNull($log->getErrorCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithErrorCodeUpdatesTheLogRow(): void
    {
        $controller = $this->makeController();
        $postParams = ['MessageSid' => 'SM123', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003'];
        $this->request->method('getHeader')
            ->willReturnMap([['X-Twilio-Signature', $this->validSignatureFor($postParams)]]);
        $this->request->method('getPostValue')->willReturn($postParams);

        $log = $this->makeMessageLog(7);
        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);
        $this->messageLogCollectionFactory->method('create')->willReturn($collection);

        $this->messageLogResource->expects(self::once())->method('save')->with($log);

        $controller->execute();

        self::assertSame('undelivered', $log->getStatus());
        self::assertSame('30003', $log->getErrorCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithUnknownMessageSidReturnsOkWithoutSaving(): void
    {
        $controller = $this->makeController();
        $postParams = ['MessageSid' => 'SM-unknown', 'MessageStatus' => 'delivered'];
        $this->request->method('getHeader')
            ->willReturnMap([['X-Twilio-Signature', $this->validSignatureFor($postParams)]]);
        $this->request->method('getPostValue')->willReturn($postParams);

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
    public function testValidSignatureWithMissingMessageSidReturnsInvalidPayload(): void
    {
        $controller = $this->makeController();
        $postParams = ['MessageStatus' => 'delivered'];
        $this->request->method('getHeader')
            ->willReturnMap([['X-Twilio-Signature', $this->validSignatureFor($postParams)]]);
        $this->request->method('getPostValue')->willReturn($postParams);

        $this->messageLogCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false, 'reason' => 'invalid_payload']);

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
