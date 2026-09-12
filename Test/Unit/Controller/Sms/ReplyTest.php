<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Sms;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Sms\Reply;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\Conversation\InboundMessageProcessor;
use Ordo\Automation\Model\Sms\CallbackUrlBuilder;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Twilio\Security\RequestValidator;

/**
 * Same "verify with a real Twilio\Security\RequestValidator, invalid signature never reaches the
 * processor" shape as StatusCallbackTest — this is a second, distinct trust boundary at a
 * different URL, so it gets its own dedicated coverage rather than assuming StatusCallbackTest
 * already proves it.
 */
class ReplyTest extends AbstractFrontendActionTestCase
{
    private const AUTH_TOKEN = 'secret-token';
    private const REPLY_URL = 'https://example.com/ordo/sms/reply';

    private JsonFactory $resultJsonFactory;
    private Config $config;
    private CallbackUrlBuilder $callbackUrlBuilder;
    private InboundMessageProcessor&\PHPUnit\Framework\MockObject\MockObject $inboundMessageProcessor;
    private LoggerInterface $logger;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getTwilioAuthToken')->willReturn(self::AUTH_TOKEN);
        $this->callbackUrlBuilder = $this->createStub(CallbackUrlBuilder::class);
        $this->callbackUrlBuilder->method('getSmsReplyUrl')->willReturn(self::REPLY_URL);
        $this->inboundMessageProcessor = $this->createMock(InboundMessageProcessor::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->jsonResult->method('setHttpResponseCode')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): Reply
    {
        return new Reply(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->config,
            $this->callbackUrlBuilder,
            $this->inboundMessageProcessor,
            $this->logger
        );
    }

    private function validSignatureFor(array $postParams): string
    {
        return (new RequestValidator(self::AUTH_TOKEN))->computeSignature(self::REPLY_URL, $postParams);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInvalidSignatureIsRejectedWithoutProcessingTheMessage(): void
    {
        $controller = $this->makeController();
        $postParams = ['From' => '+15551234567', 'Body' => 'STOP'];
        $this->request->method('getHeader')->willReturnMap([['X-Twilio-Signature', 'forged']]);
        $this->request->method('getPostValue')->willReturn($postParams);

        $this->inboundMessageProcessor->expects(self::never())->method('process');
        $this->logger->expects(self::once())->method('error');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(403);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMissingSignatureHeaderIsRejected(): void
    {
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturn(false);
        $this->request->method('getPostValue')->willReturn(['From' => '+15551234567', 'Body' => 'hi']);

        $this->inboundMessageProcessor->expects(self::never())->method('process');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(403);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureDelegatesToInboundMessageProcessor(): void
    {
        $controller = $this->makeController();
        $postParams = ['From' => '+15551234567', 'Body' => 'Thanks!', 'MessageSid' => 'SM123'];
        $this->request->method('getHeader')
            ->willReturnMap([['X-Twilio-Signature', $this->validSignatureFor($postParams)]]);
        $this->request->method('getPostValue')->willReturn($postParams);

        $this->inboundMessageProcessor->expects(self::once())->method('process')
            ->with(ConsentChannel::Sms, '+15551234567', 'Thanks!', 'SM123');
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureWithMissingFromReturnsInvalidPayload(): void
    {
        $controller = $this->makeController();
        $postParams = ['Body' => 'hi'];
        $this->request->method('getHeader')
            ->willReturnMap([['X-Twilio-Signature', $this->validSignatureFor($postParams)]]);
        $this->request->method('getPostValue')->willReturn($postParams);

        $this->inboundMessageProcessor->expects(self::never())->method('process');
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
