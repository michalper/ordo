<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\WhatsApp;

use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\WhatsApp\WhatsAppTemplateClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateClientTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private Config $config;
    private WhatsAppTemplateClient $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getWhatsAppAccessToken')->willReturn('access-token');
        $this->config->method('getWhatsAppBusinessAccountId')->willReturn('9876543210');

        // A real JsonApiClient over the mocked Curl — the HTTP mechanics under test are the
        // shared client's, exactly what production runs.
        $this->client = new WhatsAppTemplateClient(new JsonApiClient($this->curl), $this->config);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSubmitTemplatePostsToMessageTemplatesEndpoint(): void
    {
        $capturedUrl = null;
        $capturedBody = null;
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$capturedUrl, &$capturedBody) {
            $capturedUrl = $url;
            $capturedBody = json_decode($body, true);
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['id' => 'template-123']));

        $result = $this->client->submitTemplate('order_shipped_v1', 'utility', 'en_US', 'Hi {{1}}');

        self::assertSame('template-123', $result);
        self::assertStringEndsWith('/9876543210/message_templates', $capturedUrl);
        self::assertSame('order_shipped_v1', $capturedBody['name']);
        self::assertSame('UTILITY', $capturedBody['category']);
        self::assertSame('en_US', $capturedBody['language']);
        self::assertSame([['type' => 'BODY', 'text' => 'Hi {{1}}']], $capturedBody['components']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSubmitTemplateThrowsWhenResponseHasNoId(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([]));

        $this->expectException(\RuntimeException::class);
        $this->client->submitTemplate('order_shipped_v1', 'utility', 'en_US', 'Hi {{1}}');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTemplateStatusReturnsStatusAndRejectionReason(): void
    {
        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'status' => 'REJECTED',
            'rejected_reason' => 'INVALID_FORMAT',
        ]));

        $result = $this->client->getTemplateStatus('template-123');

        self::assertSame(['status' => 'REJECTED', 'rejectionReason' => 'INVALID_FORMAT'], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTemplateStatusReturnsNullReasonWhenApproved(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['status' => 'APPROVED']));

        $result = $this->client->getTemplateStatus('template-123');

        self::assertSame(['status' => 'APPROVED', 'rejectionReason' => null], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTemplateStatusThrowsOnNonSuccessHttpStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(404);
        $this->curl->method('getBody')->willReturn('{"error":"not found"}');

        $this->expectException(\RuntimeException::class);
        $this->client->getTemplateStatus('template-123');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTemplateStatusThrowsWhenResponseHasNoStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([]));

        $this->expectException(\RuntimeException::class);
        $this->client->getTemplateStatus('template-123');
    }
}
