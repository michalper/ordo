<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdAudience;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AdAudience\MetaSyncClient;
use Ordo\Automation\Model\Http\JsonApiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetaSyncClientTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private Config $config;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private MetaSyncClient $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $curlFactory = $this->createStub(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMetaAccessToken')->willReturn('meta-token');
        $this->config->method('getMetaAdAccountId')->willReturn('9999');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client = new MetaSyncClient(new JsonApiClient($curlFactory), $this->config, $this->logger);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncReplacesUsersDirectlyWhenAudienceIdAlreadySet(): void
    {
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$calls) {
            $calls[] = ['url' => $url, 'body' => json_decode($body, true)];
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $result = $this->client->sync('existing_audience_id', ['hash1', 'hash2']);

        self::assertNull($result);
        self::assertCount(1, $calls);
        self::assertStringContainsString('/existing_audience_id/usersreplace', $calls[0]['url']);
        self::assertSame(['EMAIL_SHA256'], $calls[0]['body']['payload']['schema']);
        self::assertSame([['hash1'], ['hash2']], $calls[0]['body']['payload']['data']);
        self::assertSame('meta-token', $calls[0]['body']['access_token']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncCreatesAudienceFirstWhenNoExternalIdGiven(): void
    {
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$calls) {
            $calls[] = ['url' => $url, 'body' => json_decode($body, true)];
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['id' => 'new_audience_123']),
            '{}'
        );

        $result = $this->client->sync(null, ['hash1']);

        self::assertSame('new_audience_123', $result);
        self::assertCount(2, $calls);
        self::assertStringContainsString('/act_9999/customaudiences', $calls[0]['url']);
        self::assertStringContainsString('/new_audience_123/usersreplace', $calls[1]['url']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenCreateAudienceResponseHasNoId(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $this->expectException(\RuntimeException::class);
        $this->client->sync(null, ['hash1']);
    }

    /**
     * Regression test for a real data-integrity bug a code audit found: Meta returns HTTP 200 for
     * usersreplace even when it silently rejects part of the batch (num_invalid_entries in the
     * body), which used to be completely ignored - Cron\SyncAdAudiences would record a full
     * success even when Meta accepted none of it.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSyncLogsWarningWhenMetaReportsInvalidEntries(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['num_invalid_entries' => 2]));

        $this->logger->expects(self::once())->method('warning')
            ->with(self::stringContains('rejected 2 of 2 entries'));

        $this->client->sync('existing_audience_id', ['hash1', 'hash2']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsOnNonSuccessHttpStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('{"error":"server error"}');

        $this->expectException(\RuntimeException::class);
        $this->client->sync('existing_audience_id', ['hash1']);
    }
}
