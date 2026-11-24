<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AdAudience;

use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Helper\Config;

/**
 * Exchanges the configured long-lived OAuth refresh token for a short-lived access token —
 * Data Manager API calls (GoogleAdsSyncClient) are Bearer-authenticated with this, never with
 * the refresh token itself. One real HTTP call, the standard OAuth 2.0 refresh-token grant.
 *
 * Data Manager API needs its own https://www.googleapis.com/auth/datamanager scope, granted to
 * a dedicated refresh token - a refresh token obtained for the old, now-retired Google Ads API
 * OfflineUserDataJobService integration doesn't carry it (see ADR 0035).
 *
 * @see https://developers.google.com/identity/protocols/oauth2/web-server#offline
 */
class GoogleOAuthTokenProvider
{
    private const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const int TIMEOUT_SECONDS = 10;

    // Refresh this many seconds before the token's own reported expiry, not exactly at it - a
    // cron tick syncing many audiences takes real wall-clock time, and the token must still be
    // valid for the one that happens to use it last.
    private const int EXPIRY_SAFETY_MARGIN_SECONDS = 60;

    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config
    ) {
    }

    /**
     * Found during a full-repo /simplify sweep: Cron\SyncAdAudiences calls this once per enabled
     * audience, each a fresh HTTP round-trip to Google's OAuth endpoint for the exact same
     * credentials - N audiences in one cron tick meant N redundant token refreshes. Cached here
     * for this instance's own lifetime (a single cron run, since nothing else holds this object
     * across runs) instead, keyed off the response's own expires_in.
     */
    public function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null && time() < $this->cachedAccessTokenExpiresAt) {
            return $this->cachedAccessToken;
        }

        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->post(self::TOKEN_ENDPOINT, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config->getDataManagerClientId(),
            'client_secret' => $this->config->getDataManagerClientSecret(),
            'refresh_token' => $this->config->getDataManagerRefreshToken(),
        ]);

        $status = $this->curl->getStatus();
        $body = (string) $this->curl->getBody();

        if ($status !== 200) {
            throw new \RuntimeException(sprintf('Google OAuth token refresh failed (HTTP %d): %s', $status, $body));
        }

        $decoded = json_decode($body, true);
        $accessToken = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('Google OAuth token refresh response had no access_token.');
        }

        $expiresIn = is_numeric($decoded['expires_in'] ?? null) ? (int) $decoded['expires_in'] : 0;

        $this->cachedAccessToken = $accessToken;
        $this->cachedAccessTokenExpiresAt = time() + max(0, $expiresIn - self::EXPIRY_SAFETY_MARGIN_SECONDS);

        return $accessToken;
    }
}
