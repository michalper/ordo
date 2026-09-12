<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Webhook;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Controller\Webhook\Receive;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Uses a real WebhookSignatureValidator (real HMAC-SHA256), same reasoning as WhatsApp\WebhookTest,
 * so the signature-rejection tests prove the controller's actual verification call site works,
 * not just a mocked stand-in.
 */
class ReceiveTest extends AbstractFrontendActionTestCase
{
    private const string SECRET = 'inbound-secret';

    private JsonFactory $resultJsonFactory;
    private Config $config;
    private CampaignDispatcher&\PHPUnit\Framework\MockObject\MockObject $campaignDispatcher;
    private LoggerInterface $logger;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isWebhookEnabled')->willReturn(true);
        $this->config->method('getWebhookInboundSecret')->willReturn(self::SECRET);
        $this->campaignDispatcher = $this->createMock(CampaignDispatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->jsonResult->method('setHttpResponseCode')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): Receive
    {
        return new Receive(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->config,
            new WebhookSignatureValidator(),
            $this->campaignDispatcher,
            $this->logger
        );
    }

    private function signatureFor(string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidSignatureDispatchesTheWebhookReceivedTrigger(): void
    {
        $controller = $this->makeController();
        $rawBody = '{"order_id":"ext-123"}';
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $this->campaignDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                CampaignTriggerInterface::TRIGGER_WEBHOOK_RECEIVED,
                ['webhook_payload' => ['order_id' => 'ext-123']]
            );

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true])->willReturnSelf();

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInvalidSignatureIsRejectedWithoutDispatching(): void
    {
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturn('sha256=' . str_repeat('0', 64));
        $this->request->method('getContent')->willReturn('{"order_id":"ext-123"}');

        $this->campaignDispatcher->expects(self::never())->method('dispatch');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(401)->willReturnSelf();
        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false])->willReturnSelf();

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMissingSignatureHeaderIsRejected(): void
    {
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturn(null);
        $this->request->method('getContent')->willReturn('{"order_id":"ext-123"}');

        $this->campaignDispatcher->expects(self::never())->method('dispatch');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(401)->willReturnSelf();

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDisabledConfigReturns404WithoutCheckingSignature(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isWebhookEnabled')->willReturn(false);
        $controller = $this->makeController();

        $this->campaignDispatcher->expects(self::never())->method('dispatch');
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(404)->willReturnSelf();

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInvalidJsonPayloadIsRejectedAfterAValidSignature(): void
    {
        $controller = $this->makeController();
        $rawBody = 'not json';
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $this->campaignDispatcher->expects(self::never())->method('dispatch');
        $this->jsonResult->expects(self::once())
            ->method('setData')
            ->with(['ok' => false, 'reason' => 'invalid_payload'])
            ->willReturnSelf();

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchFailureReturns500(): void
    {
        $controller = $this->makeController();
        $rawBody = '{"order_id":"ext-123"}';
        $this->request->method('getHeader')->willReturn($this->signatureFor($rawBody));
        $this->request->method('getContent')->willReturn($rawBody);

        $this->campaignDispatcher->method('dispatch')->willThrowException(new RuntimeException('db down'));
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(500)->willReturnSelf();

        $controller->execute();
    }
}
