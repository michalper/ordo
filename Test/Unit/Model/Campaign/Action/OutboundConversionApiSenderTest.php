<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\OutboundConversionApiSender;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class OutboundConversionApiSenderTest extends TestCase
{
    private JsonApiClient $jsonApiClient;
    private OutboundRateLimiter $rateLimiter;
    private MessageSendRetryQueue $messageSendRetryQueue;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->jsonApiClient = $this->createMock(JsonApiClient::class);
        $this->rateLimiter = $this->createStub(OutboundRateLimiter::class);
        $this->messageSendRetryQueue = $this->createMock(MessageSendRetryQueue::class);
        $this->messageSendRetryQueue->method('enqueueOrRethrow')->willReturnCallback(
            function (string $type, array $context, array $params, \Throwable $e): void {
                if (!empty($context[MessageSendRetryQueue::RETRY_CONTEXT_FLAG])) {
                    throw $e;
                }
                $this->messageSendRetryQueue->enqueue($type, $context, $params, $e);
            }
        );
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeSender(): OutboundConversionApiSender
    {
        return new OutboundConversionApiSender(
            $this->jsonApiClient,
            $this->rateLimiter,
            new SendRetrier(1),
            $this->messageSendRetryQueue,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendPostsThePayloadToTheGivenUrl(): void
    {
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with('https://example.com/events', ['a' => 1], [], 'Example API', [])
            ->willReturn([]);

        $context = [];
        $params = [];
        $this->makeSender()->send('send_example', 'example', 5.0, 'https://example.com/events', ['a' => 1], 'Example API', $context, $params);
    }

    /**
     * Regression: SendGa4Event/SendMetaConversionEvent pass their own auth secret as a URL query
     * param (api_secret/access_token) rather than a header, since that's what both of their own
     * APIs require - without forwarding $redactQueryParams through to JsonApiClient::postJson(),
     * a live secret would end up verbatim in the exception message this class both logs and
     * persists to ordo_message_send_retry.last_error on any failed send.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendForwardsRedactQueryParamsToJsonApiClient(): void
    {
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), ['api_secret'])
            ->willReturn([]);

        $context = [];
        $params = [];
        $this->makeSender()->send(
            'send_example',
            'example',
            5.0,
            'https://example.com/events?api_secret=shh',
            [],
            'Example API',
            $context,
            $params,
            ['api_secret']
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailureEnqueuesRetryInsteadOfThrowing(): void
    {
        $this->jsonApiClient->method('postJson')->willThrowException(new RuntimeException('boom'));
        $this->messageSendRetryQueue->expects(self::once())
            ->method('enqueue')
            ->with('send_example', self::isArray(), self::isArray(), self::isInstanceOf(RuntimeException::class));

        $context = [];
        $params = [];
        $this->makeSender()->send('send_example', 'example', 5.0, 'https://example.com/events', [], 'Example API', $context, $params);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRetryAttemptRethrowsInsteadOfReenqueuing(): void
    {
        $this->jsonApiClient->method('postJson')->willThrowException(new RuntimeException('boom'));
        $this->messageSendRetryQueue->expects(self::never())->method('enqueue');

        $context = [MessageSendRetryQueue::RETRY_CONTEXT_FLAG => true];
        $params = [];

        $this->expectException(RuntimeException::class);
        $this->makeSender()->send('send_example', 'example', 5.0, 'https://example.com/events', [], 'Example API', $context, $params);
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

        $context = [];
        $params = [];
        $this->makeSender()->send('send_example', 'example', 5.0, 'https://example.com/events', [], 'Example API', $context, $params);

        self::assertSame(2, $calls);
    }
}
