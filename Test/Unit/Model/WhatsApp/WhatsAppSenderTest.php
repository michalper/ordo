<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\WhatsApp;

use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class WhatsAppSenderTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private Config $config;
    private WhatsAppSender $sender;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getWhatsAppAccessToken')->willReturn('access-token');
        $this->config->method('getWhatsAppPhoneNumberId')->willReturn('1234567890');
        // 0 = throttling off - see the identical note in TwilioSmsSenderTest::setUp().
        $this->config->method('getWhatsAppMaxRequestsPerSecond')->willReturn(0);

        $this->sender = new WhatsAppSender(
            new JsonApiClient($this->curl),
            $this->config,
            $this->createStub(OutboundRateLimiter::class)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendPostsTemplateMessageWithPositionalParams(): void
    {
        $capturedUrl = null;
        $capturedBody = null;
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$capturedUrl, &$capturedBody) {
            $capturedUrl = $url;
            $capturedBody = json_decode($body, true);
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['messages' => [['id' => 'wamid.123']]]));

        $result = $this->sender->send('+15551234567', 'order_shipped_v1', 'en_US', ['John', 'ORD-1']);

        self::assertSame('wamid.123', $result);
        self::assertStringEndsWith('/1234567890/messages', $capturedUrl);
        self::assertSame('+15551234567', $capturedBody['to']);
        self::assertSame('template', $capturedBody['type']);
        self::assertSame('order_shipped_v1', $capturedBody['template']['name']);
        self::assertSame('en_US', $capturedBody['template']['language']['code']);
        self::assertSame(
            [['type' => 'text', 'text' => 'John'], ['type' => 'text', 'text' => 'ORD-1']],
            $capturedBody['template']['components'][0]['parameters']
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrottlesOnTheWhatsappChannelAtTheConfiguredRate(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getWhatsAppAccessToken')->willReturn('access-token');
        $config->method('getWhatsAppPhoneNumberId')->willReturn('1234567890');
        $config->method('getWhatsAppMaxRequestsPerSecond')->willReturn(7);

        $rateLimiter = $this->createMock(OutboundRateLimiter::class);
        $rateLimiter->expects(self::once())->method('throttle')->with('whatsapp', 7.0);
        $this->sender = new WhatsAppSender(new JsonApiClient($this->curl), $config, $rateLimiter);

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['messages' => [['id' => 'wamid.123']]]));

        $this->sender->send('+15551234567', 'order_shipped_v1', 'en_US', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendOmitsComponentsWhenNoParams(): void
    {
        $capturedBody = null;
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$capturedBody) {
            $capturedBody = json_decode($body, true);
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['messages' => [['id' => 'wamid.456']]]));

        $this->sender->send('+15551234567', 'welcome', 'en_US', []);

        self::assertSame([], $capturedBody['template']['components']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrowsOnNonSuccessHttpStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn('{"error":"bad request"}');

        $this->expectException(\RuntimeException::class);
        $this->sender->send('+15551234567', 'order_shipped_v1', 'en_US', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendThrowsWhenResponseHasNoMessageId(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([]));

        $this->expectException(\RuntimeException::class);
        $this->sender->send('+15551234567', 'order_shipped_v1', 'en_US', []);
    }
}
