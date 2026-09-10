<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdAudience;

use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AdAudience\GoogleAdsSyncClient;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\AdAudience\GoogleOAuthTokenProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class GoogleAdsSyncClientTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private Config $config;
    private GoogleOAuthTokenProvider&\PHPUnit\Framework\MockObject\MockObject $tokenProvider;
    private GoogleAdsSyncClient $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getGoogleAdsLoginCustomerId')->willReturn('1234567890');
        $this->config->method('getGoogleAdsDeveloperToken')->willReturn('dev-token');
        $this->tokenProvider = $this->createMock(GoogleOAuthTokenProvider::class);
        $this->tokenProvider->method('getAccessToken')->willReturn('access-123');

        $this->client = new GoogleAdsSyncClient(new JsonApiClient($this->curl), $this->config, $this->tokenProvider);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenExternalAudienceIdMissing(): void
    {
        $this->curl->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->client->sync(null, ['hash1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncCallsAllThreeRealApiStepsInOrder(): void
    {
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$calls) {
            $calls[] = ['url' => $url, 'body' => json_decode($body, true)];
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['resourceName' => 'customers/1234567890/offlineUserDataJobs/999']),
            json_encode([]),
            json_encode([])
        );

        $result = $this->client->sync('customers/1234567890/userLists/555', ['hash1', 'hash2']);

        self::assertNull($result);
        self::assertCount(3, $calls);
        self::assertStringEndsWith(':create', $calls[0]['url']);
        self::assertSame(
            'customers/1234567890/userLists/555',
            $calls[0]['body']['job']['customerMatchUserListMetadata']['userList']
        );
        self::assertStringEndsWith(':addOperations', $calls[1]['url']);
        self::assertSame(
            [['hashedEmail' => 'hash1'], ['hashedEmail' => 'hash2']],
            $calls[1]['body']['operations'][0]['create']['userIdentifiers']
        );
        self::assertStringEndsWith(':run', $calls[2]['url']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenCreateJobResponseHasNoResourceName(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Ads offlineUserDataJobs:create response had no resourceName.');
        $this->client->sync('customers/1234567890/userLists/555', ['hash1']);
    }

    /**
     * Regression test for a real data-integrity bug a code audit found: Google Ads can return
     * HTTP 200 for addOperations while still rejecting part of the batch via partialFailureError,
     * which used to be completely ignored - Cron\SyncAdAudiences would record a full success even
     * when Google Ads rejected some or all of it.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenAddOperationsReportsAPartialFailure(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['resourceName' => 'customers/1234567890/offlineUserDataJobs/999']),
            json_encode(['partialFailureError' => ['message' => 'INVALID_USER_LIST']])
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Ads rejected part of the batch');
        $this->client->sync('customers/1234567890/userLists/555', ['hash1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsOnNonSuccessHttpStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn('{"error":"bad request"}');

        $this->expectException(\RuntimeException::class);
        $this->client->sync('customers/1234567890/userLists/555', ['hash1']);
    }

    /**
     * Regression test for the ROADMAP.md Tier 4 "unbatched API call" gap: a segment larger than
     * Google Ads' own documented per-request identifier limit used to be sent as one oversized
     * addOperations call, which Google Ads rejects outright rather than truncating.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSyncSplitsALargeSegmentAcrossMultipleAddOperationsCalls(): void
    {
        $hashedEmails = array_map(static fn (int $i): string => 'hash' . $i, range(1, 10001));

        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$calls) {
            $calls[] = ['url' => $url, 'body' => json_decode($body, true)];
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnCallback(function () use (&$calls) {
            // First call ever made is offlineUserDataJobs:create - everything after that is
            // either an :addOperations batch or the final :run, both of which return an empty
            // body here (a real addOperations response has no resourceName field to fake).
            return count($calls) === 1
                ? json_encode(['resourceName' => 'customers/1234567890/offlineUserDataJobs/999'])
                : json_encode([]);
        });

        $this->client->sync('customers/1234567890/userLists/555', $hashedEmails);

        // create + 2 addOperations batches (10,000 + 1) + run = 4 total calls.
        self::assertCount(4, $calls);
        self::assertStringEndsWith(':addOperations', $calls[1]['url']);
        self::assertCount(10000, $calls[1]['body']['operations'][0]['create']['userIdentifiers']);
        self::assertStringEndsWith(':addOperations', $calls[2]['url']);
        self::assertCount(1, $calls[2]['body']['operations'][0]['create']['userIdentifiers']);
        self::assertStringEndsWith(':run', $calls[3]['url']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncSkipsAddOperationsEntirelyForAnEmptySegment(): void
    {
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url) use (&$calls) {
            $calls[] = $url;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            json_encode(['resourceName' => 'customers/1234567890/offlineUserDataJobs/999']),
            json_encode([])
        );

        $this->client->sync('customers/1234567890/userLists/555', []);

        // create + run only - no addOperations call at all for an empty batch.
        self::assertCount(2, $calls);
        self::assertStringEndsWith(':create', $calls[0]);
        self::assertStringEndsWith(':run', $calls[1]);
    }
}
