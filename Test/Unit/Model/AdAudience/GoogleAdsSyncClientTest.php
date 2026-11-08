<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdAudience;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AdAudience\GoogleAdsSyncClient;
use Ordo\Automation\Model\AdAudience\GoogleOAuthTokenProvider;
use Ordo\Automation\Model\Http\JsonApiClient;
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
        $curlFactory = $this->createStub(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getGoogleAdsAccountId')->willReturn('1234567890');
        $this->tokenProvider = $this->createMock(GoogleOAuthTokenProvider::class);
        $this->tokenProvider->method('getAccessToken')->willReturn('access-123');

        $this->client = new GoogleAdsSyncClient(new JsonApiClient($curlFactory), $this->config, $this->tokenProvider);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsWhenExternalAudienceIdMissing(): void
    {
        $this->curl->expects(self::never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->client->sync(null, ['hash1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncIngestsEveryHashedEmailInOneRealApiCall(): void
    {
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$calls) {
            $calls[] = ['url' => $url, 'body' => json_decode($body, true)];
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['requestId' => 'req-1']));

        $result = $this->client->sync('555', ['hash1', 'hash2']);

        self::assertNull($result);
        self::assertCount(1, $calls);
        self::assertStringEndsWith(':ingest', $calls[0]['url']);
        self::assertSame(
            [
                'operatingAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => '1234567890'],
                'productDestinationId' => '555',
            ],
            $calls[0]['body']['destinations'][0]
        );
        self::assertSame(
            [
                ['userData' => ['userIdentifiers' => [['emailAddress' => 'hash1']]]],
                ['userData' => ['userIdentifiers' => [['emailAddress' => 'hash2']]]],
            ],
            $calls[0]['body']['audienceMembers']
        );
        self::assertSame('HEX', $calls[0]['body']['encoding']);
        self::assertSame('ACCEPTED', $calls[0]['body']['termsOfService']['customerMatchTermsOfServiceStatus']);
    }

    /**
     * A value saved before the Data Manager API migration (ADR 0035) is the old Google Ads API
     * resource-name shape, not the bare numeric id Data Manager API's own Destination wants -
     * must keep working unchanged, not force a re-entry.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSyncExtractsTheNumericIdFromAnOldStyleResourceName(): void
    {
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$calls) {
            $calls[] = json_decode($body, true);
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['requestId' => 'req-1']));

        $this->client->sync('customers/1234567890/userLists/555', ['hash1']);

        self::assertSame('555', $calls[0]['destinations'][0]['productDestinationId']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncThrowsOnNonSuccessHttpStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn('{"error":"bad request"}');

        $this->expectException(\RuntimeException::class);
        $this->client->sync('555', ['hash1']);
    }

    /**
     * Regression test for the Google Ads API Tier 4 "unbatched API call" gap, carried over to
     * Data Manager API's own documented per-request member limit: a segment larger than that
     * must be split into multiple ingest calls, since an over-limit request is rejected outright
     * rather than truncated.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSyncSplitsALargeSegmentAcrossMultipleIngestCalls(): void
    {
        $hashedEmails = array_map(static fn (int $i): string => 'hash' . $i, range(1, 10001));

        $calls = [];
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) use (&$calls) {
            $calls[] = json_decode($body, true);
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['requestId' => 'req-1']));

        $this->client->sync('555', $hashedEmails);

        self::assertCount(2, $calls);
        self::assertCount(10000, $calls[0]['audienceMembers']);
        self::assertCount(1, $calls[1]['audienceMembers']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSyncMakesNoApiCallAtAllForAnEmptySegment(): void
    {
        $this->curl->expects(self::never())->method('post');

        $result = $this->client->sync('555', []);

        self::assertNull($result);
    }
}
