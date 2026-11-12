<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Rcs;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Ordo\Automation\Model\Rcs\TwilioRcsSender;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twilio\AuthStrategy\AuthStrategy;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Http\Response as TwilioHttpResponse;

/**
 * Same "inject a fake Twilio\Http\Client, drive the real SDK end to end" approach as
 * Test\Unit\Model\Sms\TwilioSmsSenderTest - see that class's own docblock for why.
 */
class TwilioRcsSenderTest extends TestCase
{
    private const ACCOUNT_SID = 'AC123';
    private const API_KEY_SID = 'SK123';
    private const API_KEY_SECRET = 'secret-key';
    private const MESSAGING_SERVICE_SID = 'MG123';

    private Config $config;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private OutboundRateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getTwilioAccountSid')->willReturn(self::ACCOUNT_SID);
        $this->config->method('getTwilioApiKeySid')->willReturn(self::API_KEY_SID);
        $this->config->method('getTwilioApiKeySecret')->willReturn(self::API_KEY_SECRET);
        $this->config->method('getTwilioRcsMessagingServiceSid')->willReturn(self::MESSAGING_SERVICE_SID);
        $this->config->method('getTwilioRcsMaxRequestsPerSecond')->willReturn(0);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->rateLimiter = $this->createStub(OutboundRateLimiter::class);
    }

    private function makeFakeHttpClient(int $statusCode, array $responseBody, array &$capturedRequest): TwilioHttpClient
    {
        return new class ($statusCode, $responseBody, $capturedRequest) implements TwilioHttpClient {
            public function __construct(
                private readonly int $statusCode,
                private readonly array $responseBody,
                private array &$capturedRequest
            ) {
            }

            public function request(
                string $method,
                string $url,
                array $params = [],
                array $data = [],
                array $headers = [],
                ?string $user = null,
                ?string $password = null,
                ?int $timeout = null,
                ?AuthStrategy $authStrategy = null
            ): TwilioHttpResponse {
                $this->capturedRequest = [
                    'method' => $method,
                    'url' => $url,
                    'data' => $data,
                    'user' => $user,
                    'password' => $password,
                ];

                return new TwilioHttpResponse($this->statusCode, json_encode($this->responseBody));
            }
        };
    }

    private function makeSenderWithFakeHttpClient(TwilioHttpClient $httpClient): TwilioRcsSender
    {
        return new class ($this->config, $this->logger, $this->rateLimiter, $httpClient) extends TwilioRcsSender {
            public function __construct(
                Config $config,
                LoggerInterface $logger,
                OutboundRateLimiter $rateLimiter,
                private readonly TwilioHttpClient $httpClient
            ) {
                parent::__construct($config, $logger, $rateLimiter);
            }

            protected function makeHttpClient(): TwilioHttpClient
            {
                return $this->httpClient;
            }
        };
    }

    public function testSuccessfulSendPostsToTwilioAndReturnsSid(): void
    {
        $captured = [];
        $httpClient = $this->makeFakeHttpClient(201, ['sid' => 'SM123abc', 'status' => 'queued'], $captured);
        $this->logger->expects(self::never())->method('error');

        $sid = $this->makeSenderWithFakeHttpClient($httpClient)->send('+15551234567', 'hello there');

        self::assertSame('SM123abc', $sid);
        self::assertSame(self::API_KEY_SID, $captured['user']);
        self::assertSame(self::API_KEY_SECRET, $captured['password']);
        self::assertSame('+15551234567', $captured['data']['To']);
        self::assertSame(self::MESSAGING_SERVICE_SID, $captured['data']['MessagingServiceSid']);
        self::assertSame('hello there', $captured['data']['Body']);
        self::assertArrayNotHasKey('StatusCallback', $captured['data']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrottlesOnItsOwnChannelAtTheConfiguredRate(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getTwilioAccountSid')->willReturn(self::ACCOUNT_SID);
        $this->config->method('getTwilioApiKeySid')->willReturn(self::API_KEY_SID);
        $this->config->method('getTwilioApiKeySecret')->willReturn(self::API_KEY_SECRET);
        $this->config->method('getTwilioRcsMessagingServiceSid')->willReturn(self::MESSAGING_SERVICE_SID);
        $this->config->method('getTwilioRcsMaxRequestsPerSecond')->willReturn(3);

        $this->rateLimiter = $this->createMock(OutboundRateLimiter::class);
        $this->rateLimiter->expects(self::once())->method('throttle')->with('twilio_rcs', 3.0);

        $captured = [];
        $httpClient = $this->makeFakeHttpClient(201, ['sid' => 'SM123abc', 'status' => 'queued'], $captured);

        $this->makeSenderWithFakeHttpClient($httpClient)->send('+15551234567', 'hello there');
    }

    private function makeThrowingHttpClient(TwilioException $exception): TwilioHttpClient
    {
        return new class ($exception) implements TwilioHttpClient {
            public function __construct(private readonly TwilioException $exception)
            {
            }

            public function request(
                string $method,
                string $url,
                array $params = [],
                array $data = [],
                array $headers = [],
                ?string $user = null,
                ?string $password = null,
                ?int $timeout = null,
                ?AuthStrategy $authStrategy = null
            ): TwilioHttpResponse {
                throw $this->exception;
            }
        };
    }

    public function testNonRestTwilioExceptionLogsAndThrowsRuntimeException(): void
    {
        $httpClient = $this->makeThrowingHttpClient(new TwilioException('Could not connect to Twilio'));
        $this->logger->expects(self::once())->method('error')
            ->with(self::stringContains('Could not connect to Twilio'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to reach the Twilio API.');

        $this->makeSenderWithFakeHttpClient($httpClient)->send('+15551234567', 'hello there');
    }

    public function testRestErrorLogsAndThrowsRuntimeException(): void
    {
        $captured = [];
        $httpClient = $this->makeFakeHttpClient(400, [
            'code' => 21211,
            'message' => 'Invalid To Phone Number',
            'status' => 400,
        ], $captured);
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('21211'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Twilio API rejected the RCS request.');

        $this->makeSenderWithFakeHttpClient($httpClient)->send('+15551234567', 'hello there');
    }

    public function testMakeHttpClientDefaultsToNullLettingSdkUseItsOwnClient(): void
    {
        $this->logger->expects(self::never())->method('error');

        $sender = new TwilioRcsSender($this->config, $this->logger, $this->rateLimiter);

        $method = new \ReflectionMethod($sender, 'makeHttpClient');

        self::assertNull($method->invoke($sender));
    }

    /**
     * @see \Ordo\Automation\Model\Rcs\TwilioRcsSender::getClient() - same "built once per
     * distinct credential set" reasoning as TwilioSmsSenderTest's analogous test.
     */
    private function makeCountingSenderWithFakeHttpClient(TwilioHttpClient $httpClient, array &$makeHttpClientCalls): TwilioRcsSender
    {
        return new class ($this->config, $this->logger, $this->rateLimiter, $httpClient, $makeHttpClientCalls) extends TwilioRcsSender {
            public function __construct(
                Config $config,
                LoggerInterface $logger,
                OutboundRateLimiter $rateLimiter,
                private readonly TwilioHttpClient $httpClient,
                private array &$makeHttpClientCalls
            ) {
                parent::__construct($config, $logger, $rateLimiter);
            }

            protected function makeHttpClient(): TwilioHttpClient
            {
                $this->makeHttpClientCalls[] = true;

                return $this->httpClient;
            }
        };
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testClientIsBuiltOnceAndReusedAcrossMultipleSendsWithTheSameCredentials(): void
    {
        $captured = [];
        $httpClient = $this->makeFakeHttpClient(201, ['sid' => 'SM123abc', 'status' => 'queued'], $captured);
        $calls = [];
        $sender = $this->makeCountingSenderWithFakeHttpClient($httpClient, $calls);

        $sender->send('+15551234567', 'first');
        $sender->send('+15557654321', 'second');

        self::assertCount(1, $calls);
    }
}
