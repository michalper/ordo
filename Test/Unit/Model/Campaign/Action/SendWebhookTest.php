<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\Campaign\Action\SendWebhook;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SendWebhookTest extends TestCase
{
    private Config $config;
    private JsonApiClient $jsonApiClient;
    private WebhookSignatureValidator $signatureValidator;
    private OutboundRateLimiter $rateLimiter;
    private MessageSendRetryQueue $messageSendRetryQueue;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isWebhookEnabled')->willReturn(true);
        $this->config->method('getWebhookOutboundUrl')->willReturn('https://erp.example.com/inbound');
        $this->config->method('getWebhookOutboundSecret')->willReturn('a-real-secret');
        $this->config->method('getWebhookMaxRequestsPerSecond')->willReturn(0);
        $this->jsonApiClient = $this->createMock(JsonApiClient::class);
        $this->signatureValidator = new WebhookSignatureValidator();
        $this->rateLimiter = $this->createStub(OutboundRateLimiter::class);
        $this->messageSendRetryQueue = $this->createMock(MessageSendRetryQueue::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeAction(): SendWebhook
    {
        return new SendWebhook(
            $this->config,
            $this->jsonApiClient,
            $this->signatureValidator,
            $this->rateLimiter,
            new SendRetrier(1),
            $this->messageSendRetryQueue,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendsSignedPayloadToConfiguredUrl(): void
    {
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://erp.example.com/inbound',
                self::callback(static function (array $payload): bool {
                    return $payload['event'] === 'order_placed' && $payload['campaign_id'] === 7;
                }),
                self::callback(function (array $headers): bool {
                    return isset($headers[WebhookSignatureValidator::SIGNATURE_HEADER])
                        && str_starts_with($headers[WebhookSignatureValidator::SIGNATURE_HEADER], 'sha256=');
                }),
                'Ordo webhook'
            )
            ->willReturn([]);

        $context = ['trigger_event' => 'order_placed', 'campaign_id' => 7, 'customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEventParamOverridesTriggerEvent(): void
    {
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(
                self::anything(),
                self::callback(static fn (array $payload): bool => $payload['event'] === 'custom_event'),
                self::anything(),
                self::anything()
            )
            ->willReturn([]);

        $context = ['trigger_event' => 'order_placed'];
        $this->makeAction()->execute($context, ['event' => 'custom_event']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenWebhooksDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isWebhookEnabled')->willReturn(false);
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['trigger_event' => 'order_placed'];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenNoOutboundUrlConfigured(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isWebhookEnabled')->willReturn(true);
        $this->config->method('getWebhookOutboundUrl')->willReturn('');
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['trigger_event' => 'order_placed'];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSkippedWhenNoOutboundSecretConfigured(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isWebhookEnabled')->willReturn(true);
        $this->config->method('getWebhookOutboundUrl')->willReturn('https://erp.example.com/inbound');
        $this->config->method('getWebhookOutboundSecret')->willReturn('');
        $this->jsonApiClient->expects(self::never())->method('postJson');

        $context = ['trigger_event' => 'order_placed'];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailureEnqueuesRetryInsteadOfThrowing(): void
    {
        $this->jsonApiClient->method('postJson')->willThrowException(new RuntimeException('boom'));
        $this->messageSendRetryQueue->expects(self::once())
            ->method('enqueue')
            ->with('send_webhook', self::isArray(), self::isArray(), self::isInstanceOf(RuntimeException::class));

        $context = ['trigger_event' => 'order_placed'];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRetryAttemptRethrowsInsteadOfReenqueuing(): void
    {
        $this->jsonApiClient->method('postJson')->willThrowException(new RuntimeException('boom'));
        $this->messageSendRetryQueue->expects(self::never())->method('enqueue');

        $context = [
            'trigger_event' => 'order_placed',
            MessageSendRetryQueue::RETRY_CONTEXT_FLAG => true,
        ];

        $this->expectException(RuntimeException::class);
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTransientFailureIsRetriedAndEventuallySucceeds(): void
    {
        $calls = 0;
        $this->jsonApiClient->method('postJson')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            if ($calls < 2) {
                throw new RuntimeException('temporary');
            }
            return [];
        });
        $this->messageSendRetryQueue->expects(self::never())->method('enqueue');

        $context = ['trigger_event' => 'order_placed'];
        $this->makeAction()->execute($context, []);

        self::assertSame(2, $calls);
    }
}
