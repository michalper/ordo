<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdAudience;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AdAudience\TempFileFactory;
use Ordo\Automation\Model\AdAudience\TikTokSyncClient;
use Ordo\Automation\Model\Http\JsonApiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TikTokSyncClientTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private Config $config;
    private TikTokSyncClient $client;

    /** @var array<int, array{name: string, value: string}> */
    private array $headers = [];

    /** @var array<int, mixed> */
    private array $postFields = [];

    protected function setUp(): void
    {
        $this->headers = [];
        $this->postFields = [];
        $this->curl = $this->createMock(Curl::class);
        $curlFactory = $this->createStub(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->curl->method('addHeader')->willReturnCallback(
            function (string $name, string $value): void {
                $this->headers[] = ['name' => $name, 'value' => $value];
            }
        );
        $this->curl->method('setOption')->willReturnCallback(
            function (int $name, mixed $value): void {
                if ($name === CURLOPT_POSTFIELDS) {
                    $this->postFields[] = $value;
                }
            }
        );

        $this->config = $this->createStub(Config::class);
        $this->config->method('getTiktokAccessToken')->willReturn('tiktok-token');
        $this->config->method('getTiktokAdvertiserId')->willReturn('555');

        $this->client = new TikTokSyncClient(
            new JsonApiClient($curlFactory),
            $curlFactory,
            $this->config,
            new TempFileFactory()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenNoTemporaryFileCanBeCreated(): void
    {
        $tempFileFactory = $this->createStub(TempFileFactory::class);
        $tempFileFactory->method('create')->willReturn(false);
        $curlFactory = $this->createStub(CurlFactory::class);

        $client = new TikTokSyncClient(
            new JsonApiClient($curlFactory),
            $curlFactory,
            $this->config,
            $tempFileFactory
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not create a temporary file for the TikTok audience upload.');

        $client->sync(null, ['hashed-email']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncCreatesAudienceFirstWhenNoExternalIdGiven(): void
    {
        $urls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url) use (&$urls): void {
            $urls[] = $url;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['code' => 0, 'data' => ['file_path' => 'uploaded/file/path/123']]),
            json_encode(['code' => 0, 'data' => ['custom_audience_id' => 'new_audience_123']])
        );

        $result = $this->client->sync(null, ['hash1', 'hash2']);

        self::assertSame('new_audience_123', $result);
        self::assertCount(2, $urls);
        self::assertStringContainsString('/dmp/custom_audience/file/upload/', $urls[0]);
        self::assertStringNotContainsString('Access-Token=', $urls[0]);
        self::assertStringContainsString('/dmp/custom_audience/create/', $urls[1]);

        // Access-Token is a request header, not a query param - a live call confirmed the
        // published spec's "query param" claim doesn't match the real endpoint's own behavior
        // (see TikTokSyncClient's own docblock).
        self::assertContains(['name' => 'Access-Token', 'value' => 'tiktok-token'], $this->headers);

        $uploadFields = $this->postFields[0];
        self::assertSame('555', $uploadFields['advertiser_id']);
        self::assertSame('EMAIL_SHA256', $uploadFields['calculate_type']);
        self::assertInstanceOf(\CURLFile::class, $uploadFields['file']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncReplacesMembersDirectlyWhenAudienceIdAlreadySet(): void
    {
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, $body) use (&$calls): void {
            $calls[] = ['url' => $url, 'body' => is_string($body) ? json_decode($body, true) : $body];
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['code' => 0, 'data' => ['file_path' => 'uploaded/file/path/123']]),
            json_encode(['code' => 0])
        );

        $result = $this->client->sync('existing_audience_id', ['hash1']);

        self::assertNull($result);
        self::assertCount(2, $calls);
        self::assertStringContainsString('/dmp/custom_audience/update/', $calls[1]['url']);
        self::assertSame('existing_audience_id', $calls[1]['body']['custom_audience_id']);
        self::assertSame(['uploaded/file/path/123'], $calls[1]['body']['file_paths']);
        self::assertSame('REPLACE', $calls[1]['body']['action']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenUploadResponseHasNoFilePath(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['code' => 0]));

        $this->expectException(RuntimeException::class);
        $this->client->sync(null, ['hash1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsOnUploadHttpFailure(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('{"message":"server error"}');

        $this->expectException(RuntimeException::class);
        $this->client->sync(null, ['hash1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenUploadResponseIsNotValidJson(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        // Not malformed JSON (JsonApiClient would already reject that) - valid JSON that decodes
        // to a scalar rather than the array shape assertSuccess() requires.
        $this->curl->method('getBody')->willReturn('42');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TikTok Marketing API file upload response was not valid JSON.');
        $this->client->sync(null, ['hash1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenCreateResponseHasNoAudienceId(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['code' => 0, 'data' => ['file_path' => 'uploaded/file/path/123']]),
            json_encode(['code' => 0])
        );

        $this->expectException(RuntimeException::class);
        $this->client->sync(null, ['hash1']);
    }

    /**
     * TikTok's own API returns HTTP 200 for both success and failure - confirmed live against
     * the real endpoint (see TikTokSyncClient's own docblock). This is the regression test for
     * that: a 200 response carrying a real TikTok error code must still be treated as a failure.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenUploadReportsAnErrorCodeDespiteHttp200(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            json_encode(['code' => 40105, 'message' => 'Access token is incorrect or has been revoked.'])
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/incorrect or has been revoked/');
        $this->client->sync(null, ['hash1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenCreateReportsAnErrorCodeDespiteHttp200(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['code' => 0, 'data' => ['file_path' => 'uploaded/file/path/123']]),
            json_encode(['code' => 40002, 'message' => 'Invalid parameter.'])
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid parameter/');
        $this->client->sync(null, ['hash1']);
    }
}
