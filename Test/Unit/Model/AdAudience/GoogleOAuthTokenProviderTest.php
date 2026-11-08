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
