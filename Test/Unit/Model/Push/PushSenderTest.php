<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Push;

use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Push\BaseSixtyFourUrl;
use Ordo\Automation\Model\Push\Exception\SubscriptionGoneException;
use Ordo\Automation\Model\Push\Der;
use Ordo\Automation\Model\Push\PushEndpointValidator;
use Ordo\Automation\Model\Push\PushSender;
use Ordo\Automation\Model\Push\VapidTokenBuilder;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Ordo\Automation\Model\Push\WebPushCrypto;
use Ordo\Automation\Model\PushSubscription;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class PushSenderTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private Config $config;
    private VapidTokenBuilder&\PHPUnit\Framework\MockObject\MockObject $vapidTokenBuilder;
    private PushEndpointValidator $pushEndpointValidator;
    private PushSender $sender;
    private PushSubscription $subscription;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getVapidPublicKey')->willReturn('public-key');
        $this->config->method('getVapidPrivateKey')->willReturn('private-key');
        $this->config->method('getVapidSubject')->willReturn('mailto:ops@example.com');
        // 0 = throttling off - see the identical note in TwilioSmsSenderTest::setUp().
        $this->config->method('getPushMaxRequestsPerSecond')->willReturn(0);
        $this->vapidTokenBuilder = $this->createMock(VapidTokenBuilder::class);
        $this->vapidTokenBuilder->method('buildAuthorizationHeader')->willReturn('vapid t=jwt, k=public-key');
        $this->pushEndpointValidator = $this->createStub(PushEndpointValidator::class);
        $this->pushEndpointValidator->method('isAllowed')->willReturn(true);

        $base64Url = new BaseSixtyFourUrl();
        $this->sender = new PushSender(
            $this->curl,
            $this->config,
            $this->vapidTokenBuilder,
            new WebPushCrypto(new Der(), $base64Url),
            $this->pushEndpointValidator,
            $this->createStub(OutboundRateLimiter::class)
        );

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);
        $x = str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $resource = $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $this->subscription = new PushSubscription(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $resource
        );
        $this->subscription->setEntityId(7);
        $this->subscription->setEndpoint('https://fcm.googleapis.com/fcm/send/abc123');
        $this->subscription->setP256dhKey($base64Url->encode("\x04" . $x . $y));
        $this->subscription->setAuthKey($base64Url->encode(random_bytes(16)));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendPostsEncryptedBodyWithExpectedHeaders(): void
    {
        $capturedHeaders = [];
        $this->curl->method('addHeader')->willReturnCallback(function (string $name, string $value) use (&$capturedHeaders) {
            $capturedHeaders[$name] = $value;
        });
        $capturedBody = null;
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$capturedBody) {
            $capturedBody = $body;
        });
        $this->curl->method('getStatus')->willReturn(201);

        $this->sender->send($this->subscription, '{"title":"Hi"}');

        self::assertSame('application/octet-stream', $capturedHeaders['Content-Type']);
        self::assertSame('aes128gcm', $capturedHeaders['Content-Encoding']);
        self::assertSame('vapid t=jwt, k=public-key', $capturedHeaders['Authorization']);
        self::assertNotEmpty($capturedBody);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrottlesOnThePushChannelAtTheConfiguredRate(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getVapidPublicKey')->willReturn('public-key');
        $config->method('getVapidPrivateKey')->willReturn('private-key');
        $config->method('getVapidSubject')->willReturn('mailto:ops@example.com');
        $config->method('getPushMaxRequestsPerSecond')->willReturn(15);

        $rateLimiter = $this->createMock(OutboundRateLimiter::class);
        $rateLimiter->expects(self::once())->method('throttle')->with('push', 15.0);

        $this->sender = new PushSender(
            $this->curl,
            $config,
            $this->vapidTokenBuilder,
            new WebPushCrypto(new Der(), new BaseSixtyFourUrl()),
            $this->pushEndpointValidator,
            $rateLimiter
        );

        $this->curl->method('getStatus')->willReturn(201);

        $this->sender->send($this->subscription, '{"title":"Hi"}');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrowsSubscriptionGoneOn410(): void
    {
        $this->curl->method('getStatus')->willReturn(410);

        $this->expectException(SubscriptionGoneException::class);
        $this->sender->send($this->subscription, '{"title":"Hi"}');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrowsSubscriptionGoneOn404(): void
    {
        $this->curl->method('getStatus')->willReturn(404);

        $this->expectException(SubscriptionGoneException::class);
        $this->sender->send($this->subscription, '{"title":"Hi"}');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrowsRuntimeExceptionOnOtherFailure(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('server error');

        $this->expectException(\RuntimeException::class);
        $this->sender->send($this->subscription, '{"title":"Hi"}');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendRejectsAnEndpointThatFailsRevalidation(): void
    {
        $this->pushEndpointValidator = $this->createStub(PushEndpointValidator::class);
        $this->pushEndpointValidator->method('isAllowed')->willReturn(false);
        $this->sender = new PushSender(
            $this->curl,
            $this->config,
            $this->vapidTokenBuilder,
            new WebPushCrypto(new Der(), new BaseSixtyFourUrl()),
            $this->pushEndpointValidator,
            $this->createStub(OutboundRateLimiter::class)
        );

        $this->curl->expects(self::never())->method('post');
        $this->expectException(\RuntimeException::class);
        $this->sender->send($this->subscription, '{"title":"Hi"}');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendTruncatesResponseBodyInFailureMessage(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn(str_repeat('x', 1000));

        try {
            $this->sender->send($this->subscription, '{"title":"Hi"}');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertLessThan(300, strlen($e->getMessage()));
        }
    }
}
