<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Http;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Ordo\Automation\Model\Http\JsonApiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class JsonApiClientTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private CurlFactory&\PHPUnit\Framework\MockObject\MockObject $curlFactory;
    private JsonApiClient $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->method('create')->willReturn($this->curl);
        $this->client = new JsonApiClient($this->curlFactory);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostJsonSendsPayloadAndExtraHeaders(): void
    {
        $capturedHeaders = [];
        $this->curl->method('addHeader')->willReturnCallback(function (string $name, string $value) use (&$capturedHeaders) {
            $capturedHeaders[$name] = $value;
        });
        $capturedBody = null;
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$capturedBody) {
            $capturedBody = $body;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"ok":true}');

        $result = $this->client->postJson(
            'https://api.example.com/endpoint',
            ['foo' => 'bar'],
            ['Authorization' => 'Bearer token123'],
            'Example API'
        );

        self::assertSame('application/json', $capturedHeaders['Content-Type']);
        self::assertSame('Bearer token123', $capturedHeaders['Authorization']);
        self::assertSame('{"foo":"bar"}', $capturedBody);
        self::assertSame(['ok' => true], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostJsonThrowsWithServiceNameOnNonSuccessStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('server error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Example API request to .* failed \(HTTP 500\): server error$/');

        $this->client->postJson('https://api.example.com/endpoint', [], [], 'Example API');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostJsonReturnsEmptyArrayForNonJsonResponse(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('not json');

        self::assertSame([], $this->client->postJson('https://api.example.com/endpoint', [], [], 'Example API'));
    }

    /**
     * Regression: SendGa4Event/SendMetaConversionEvent pass their own live secret
     * (api_secret/access_token) as a $url query param, since that's what both of their own APIs
     * require. Without redaction, a failed request would put that secret verbatim into the
     * exception message - which OutboundConversionApiSender both logs and persists to
     * ordo_message_send_retry.last_error.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPostJsonRedactsNamedQueryParamsFromFailureMessage(): void
    {
        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('invalid token');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Example API request to https://api.example.com/endpoint?measurement_id=G-ABC123&api_secret=%5Bredacted%5D failed (HTTP 401): invalid token'
        );

        $this->client->postJson(
            'https://api.example.com/endpoint?measurement_id=G-ABC123&api_secret=a-real-secret',
            [],
            [],
            'Example API',
            ['api_secret']
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostJsonRedactLeavesUrlUnchangedWhenThereIsNoQueryStringToRedact(): void
    {
        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('invalid token');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Example API request to https://api.example.com/endpoint failed (HTTP 401): invalid token'
        );

        $this->client->postJson(
            'https://api.example.com/endpoint',
            [],
            [],
            'Example API',
            ['api_secret']
        );
    }

    /**
     * Every other existing caller (webhook, TikTok, Google Ads/Meta ad-audience sync, WhatsApp)
     * passes no $redactQueryParams at all - confirms the new 5th parameter is fully optional and
     * changes nothing for them.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPostJsonWithoutRedactQueryParamsLeavesUrlUnchanged(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('server error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Example API request to https://api.example.com/endpoint?api_secret=a-real-secret failed (HTTP 500): server error'
        );

        $this->client->postJson(
            'https://api.example.com/endpoint?api_secret=a-real-secret',
            [],
            [],
            'Example API'
        );
    }

    /**
     * Regression: a shared Curl instance never clears headers it was told to add, so one
     * caller's Authorization/developer-token headers would otherwise leak into the next,
     * unrelated caller's request within the same process (Cron\SyncAdAudiences calls both
     * GoogleAdsSyncClient and MetaSyncClient in the same execute()). CurlFactory::create() must
     * be called once per postJson()/getJson() call, not reused.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testEachCallGetsItsOwnFreshCurlInstance(): void
    {
        $firstCurl = $this->createMock(Curl::class);
        $firstCurl->method('getStatus')->willReturn(200);
        $firstCurl->method('getBody')->willReturn('{}');

        $secondCurl = $this->createMock(Curl::class);
        $secondCurl->method('getStatus')->willReturn(200);
        $secondCurl->method('getBody')->willReturn('{}');

        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->expects(self::exactly(2))->method('create')
            ->willReturnOnConsecutiveCalls($firstCurl, $secondCurl);
        $this->client = new JsonApiClient($this->curlFactory);

        $firstCurlHeaders = [];
        $firstCurl->method('addHeader')->willReturnCallback(function (string $name, string $value) use (&$firstCurlHeaders) {
            $firstCurlHeaders[$name] = $value;
        });
        $secondCurlHeaders = [];
        $secondCurl->method('addHeader')->willReturnCallback(function (string $name, string $value) use (&$secondCurlHeaders) {
            $secondCurlHeaders[$name] = $value;
        });

        $this->client->postJson('https://api.example.com/first', [], ['Authorization' => 'Bearer first-token'], 'First API');
        $this->client->postJson('https://api.example.com/second', [], [], 'Second API');

        self::assertSame('Bearer first-token', $firstCurlHeaders['Authorization']);
        self::assertArrayNotHasKey('Authorization', $secondCurlHeaders);
    }
}
