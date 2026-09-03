<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Sms;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Sms\CallbackUrlBuilder;
use Ordo\Automation\Model\Sms\OptedOutException;
use Ordo\Automation\Model\Sms\TwilioSmsSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twilio\AuthStrategy\AuthStrategy;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Http\Response as TwilioHttpResponse;

/**
 * Rather than hand-mocking Twilio\Rest\Client's internals (a large, generated class graph that
 * would be brittle to mock and wouldn't actually exercise the SDK's own request-building/error-
 * parsing logic), this injects a fake Twilio\Http\Client — the SDK's own documented seam for
 * substituting the transport — so these tests drive the real SDK code path (real request
 * shape, real RestException parsing) end to end, only faking the actual network call.
 */
class TwilioSmsSenderTest extends TestCase
{
    private const ACCOUNT_SID = 'AC123';
    private const AUTH_TOKEN = 'secret-token';
    private const FROM_NUMBER = '+15550001111';
    private const CALLBACK_URL = 'https://example.com/ordo/sms/statuscallback';

    private Config $config;
    private CallbackUrlBuilder $callbackUrlBuilder;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getTwilioAccountSid')->willReturn(self::ACCOUNT_SID);
        $this->config->method('getTwilioAuthToken')->willReturn(self::AUTH_TOKEN);
        $this->config->method('getTwilioFromNumber')->willReturn(self::FROM_NUMBER);

        $this->callbackUrlBuilder = $this->createStub(CallbackUrlBuilder::class);
        $this->callbackUrlBuilder->method('getSmsStatusCallbackUrl')->willReturn(self::CALLBACK_URL);

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * A fake Twilio\Http\Client that records the request it received and returns a canned
     * response — this is what makes the SDK's own Twilio\Rest\Client usable in a unit test
     * without a real network call.
     */
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

    /**
     * TwilioSmsSender builds its own Twilio\Rest\Client internally rather than accepting one via
     * DI (Magento can't autowire a third-party SDK object from config values), so it exposes a
     * protected makeHttpClient() seam specifically for this — this anonymous subclass overrides
     * it to inject the fake HTTP client above instead of the SDK's real CurlClient default.
     */
    private function makeSenderWithFakeHttpClient(TwilioHttpClient $httpClient): TwilioSmsSender
    {
        return new class ($this->config, $this->callbackUrlBuilder, $this->logger, $httpClient) extends TwilioSmsSender {
            public function __construct(
                Config $config,
                CallbackUrlBuilder $callbackUrlBuilder,
                LoggerInterface $logger,
                private readonly TwilioHttpClient $httpClient
            ) {
                parent::__construct($config, $callbackUrlBuilder, $logger);
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
        self::assertSame(self::ACCOUNT_SID, $captured['user']);
        self::assertSame(self::AUTH_TOKEN, $captured['password']);
        self::assertSame('+15551234567', $captured['data']['To']);
        self::assertSame(self::FROM_NUMBER, $captured['data']['From']);
        self::assertSame('hello there', $captured['data']['Body']);
        self::assertSame(self::CALLBACK_URL, $captured['data']['StatusCallback']);
    }

    public function testOptedOutErrorCodeThrowsOptedOutExceptionWithoutLoggingAsError(): void
    {
        $captured = [];
        $httpClient = $this->makeFakeHttpClient(400, [
            'code' => 21610,
            'message' => 'Attempt to send to unsubscribed recipient',
            'status' => 400,
        ], $captured);
        $this->logger->expects(self::never())->method('error');

        $this->expectException(OptedOutException::class);

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

    public function testMakeHttpClientDefaultsToNullLettingSdkUseItsOwnClient(): void
    {
        $this->logger->expects(self::never())->method('error');

        $sender = new TwilioSmsSender($this->config, $this->callbackUrlBuilder, $this->logger);

        $method = new \ReflectionMethod($sender, 'makeHttpClient');

        self::assertNull($method->invoke($sender));
    }

    public function testOtherRestErrorLogsAndThrowsRuntimeException(): void
    {
        $captured = [];
        $httpClient = $this->makeFakeHttpClient(400, [
            'code' => 21211,
            'message' => 'Invalid To Phone Number',
            'status' => 400,
        ], $captured);
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('21211'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Twilio API rejected the SMS request.');

        $this->makeSenderWithFakeHttpClient($httpClient)->send('+15551234567', 'hello there');
    }
}
