<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AdAudience;

use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AdAudience\GoogleOAuthTokenProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class GoogleOAuthTokenProviderTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private Config $config;
    private GoogleOAuthTokenProvider $provider;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getDataManagerClientId')->willReturn('client-id');
        $this->config->method('getDataManagerClientSecret')->willReturn('client-secret');
        $this->config->method('getDataManagerRefreshToken')->willReturn('refresh-token');

        $this->provider = new GoogleOAuthTokenProvider($this->curl, $this->config);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAccessTokenPostsRealGrantAndReturnsToken(): void
    {
        $this->curl->expects(self::once())->method('post')
            ->with('https://oauth2.googleapis.com/token', [
                'grant_type' => 'refresh_token',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'refresh_token' => 'refresh-token',
            ]);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['access_token' => 'access-123']));

        self::assertSame('access-123', $this->provider->getAccessToken());
    }

    /**
     * Regression for a full-repo /simplify finding: Cron\SyncAdAudiences calls getAccessToken()
     * once per enabled audience - without caching, N audiences in one cron tick meant N
     * redundant HTTP round-trips to Google's OAuth endpoint for the exact same credentials.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetAccessTokenCachesAStillValidTokenAcrossCalls(): void
    {
        $this->curl->expects(self::once())->method('post');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')
            ->willReturn(json_encode(['access_token' => 'access-123', 'expires_in' => 3600]));

        self::assertSame('access-123', $this->provider->getAccessToken());
        self::assertSame('access-123', $this->provider->getAccessToken());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAccessTokenRefetchesOnceTheCachedTokenHasExpired(): void
    {
        $this->curl->expects(self::exactly(2))->method('post');
        $this->curl->method('getStatus')->willReturn(200);
        // expires_in below the safety margin means it's already treated as expired immediately.
        $this->curl->method('getBody')
            ->willReturn(json_encode(['access_token' => 'access-123', 'expires_in' => 5]));

        self::assertSame('access-123', $this->provider->getAccessToken());
        self::assertSame('access-123', $this->provider->getAccessToken());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAccessTokenThrowsOnNon200Status(): void
    {
        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('{"error":"invalid_grant"}');

        $this->expectException(\RuntimeException::class);
        $this->provider->getAccessToken();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAccessTokenThrowsWhenResponseHasNoAccessToken(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $this->expectException(\RuntimeException::class);
        $this->provider->getAccessToken();
    }
}
