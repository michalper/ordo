<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AdAudience;

use Magento\Framework\HTTP\Client\CurlFactory;
use Ordo\Automation\Api\AdAudience\SyncClientInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Http\JsonApiClient;
use RuntimeException;

/**
 * Syncs a hashed-email list to TikTok as a Custom Audience, via the Marketing API's two-step
 * Customer File flow: upload the data file (POST .../file/upload/, multipart/form-data) to get
 * a file_path, then create (first sync) or update with action=REPLACE (every later sync) the
 * audience referencing that file_path. Real HTTP calls to the documented REST endpoints, same
 * "plain HTTP, no new SDK" choice GoogleAdsSyncClient/MetaSyncClient already made.
 *
 * Two real findings from testing live against TikTok's actual endpoints (unauthenticated calls,
 * no real account access yet - see this class's own "not yet verified" note below for what that
 * still leaves open), not assumed from documentation alone:
 *
 * - **"Access-Token" is sent as a request HEADER, not a URL query parameter** - TikTok's own
 *   published OpenAPI spec (github.com/tiktok/tiktok-business-api-sdk, `in: query`) says query
 *   parameter, but a live call with it as a query param got back `{"code":40104,"message":"The
 *   access_token is empty."}` - the same response as sending no token at all, meaning the real
 *   endpoint isn't reading it there. Sent as an `Access-Token` header instead, the same
 *   unauthenticated call instead got `{"code":40105,"message":"Access token is incorrect or has
 *   been revoked."}` - a materially different, more specific error, proving the header value was
 *   actually read and reached token validation. Confirmed on both the file-upload and the
 *   create/update endpoints. The published spec is wrong (or stale) on this point; the live
 *   endpoint's own real behavior is what this class follows.
 * - **Every response is HTTP 200, success or failure** - TikTok signals errors through the JSON
 *   body's own `code`/`message` fields (`code: 0` means success), not the HTTP status, confirmed
 *   by both error responses above returning HTTP 200. `Model\Http\JsonApiClient::postJson()`
 *   only checks HTTP status, so every call here additionally checks the decoded `code` field
 *   itself (see `assertSuccess()`) - without that check, a real TikTok-reported failure would
 *   have looked like a success to `Cron\SyncAdAudiences`.
 *
 * NOT built on Model\Http\JsonApiClient for the file-upload step - that class is JSON-only
 * (Content-Type: application/json always), and this call is multipart/form-data with a binary
 * file, the same reasoning Model\Http\JsonApiClient's own docblock already gives for why
 * Model\Push\PushSender isn't built on it either. The create/update calls, which ARE plain JSON,
 * do reuse it (headers passed through its own $headers parameter).
 *
 * Honestly flagged, still not fully verified against a real authenticated account (no TikTok
 * Marketing API access at the time this was written - see ROADMAP.md): the exact response field
 * holding the new audience id (used here as "custom_audience_id", matching the request field's
 * own naming and a third-party reproduction of the response, since the published OpenAPI spec
 * itself leaves the response `data` object's properties undocumented) and the exact raw file
 * content format (used here as one hashed value per line, no header row - the file ships
 * calculate_type as its own separate field, so a header row seems redundant, unlike the Ads
 * Manager UI's own human-upload flow which does want one). Both should be confirmed for real the
 * moment a valid access token exists, the same bar every other ad-platform integration in this
 * module was held to before being trusted.
 *
 * @see https://github.com/tiktok/tiktok-business-api-sdk/blob/main/yml_files/dmp_custom_audience_file_upload.yml
 * @see https://github.com/tiktok/tiktok-business-api-sdk/blob/main/yml_files/dmp_custom_audience_create.yml
 * @see https://github.com/tiktok/tiktok-business-api-sdk/blob/main/yml_files/dmp_custom_audience_update.yml
 */
class TikTokSyncClient implements SyncClientInterface
{
    private const string API_BASE_URL = 'https://business-api.tiktok.com/open_api/v1.3';
    private const string CALCULATE_TYPE = 'EMAIL_SHA256';

    public function __construct(
        private readonly JsonApiClient $jsonApiClient,
        private readonly CurlFactory $curlFactory,
        private readonly Config $config
    ) {
    }

    public function sync(?string $externalAudienceId, array $hashedEmails): ?string
    {
        $accessToken = $this->config->getTiktokAccessToken();
        $advertiserId = $this->config->getTiktokAdvertiserId();

        $filePath = $this->uploadFile($hashedEmails, $accessToken, $advertiserId);

        if ($externalAudienceId === null || $externalAudienceId === '') {
            return $this->createAudience($filePath, $accessToken, $advertiserId);
        }

        $this->replaceAudienceMembers($externalAudienceId, $filePath, $accessToken, $advertiserId);

        return null;
    }

    /**
     * @param array<int, string> $hashedEmails
     */
    private function uploadFile(array $hashedEmails, string $accessToken, string $advertiserId): string
    {
        // One hashed value per line - see this class's own docblock for why this, specifically,
        // is still not confirmed against a real account.
        $fileContent = implode("\n", $hashedEmails);
        // A real filesystem path is required here, not a stream - \CURLFile (below) only accepts
        // one, to hand libcurl the file for real streamed multipart upload. Transient and
        // request-scoped (deleted in the finally block below), never user-facing content.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $tmpFile = tempnam(sys_get_temp_dir(), 'ordo_tiktok_audience_');
        if ($tmpFile === false) {
            throw new RuntimeException('Could not create a temporary file for the TikTok audience upload.');
        }

        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            file_put_contents($tmpFile, $fileContent);

            $curl = $this->curlFactory->create();
            $curl->setTimeout(30);
            $curl->addHeader('Access-Token', $accessToken);
            // Overrides post()'s own http_build_query($params) encoding (applied first, then
            // every _curlUserOptions entry overrides it) - this is how a CURLFile member forces
            // PHP's curl extension into real multipart/form-data encoding instead of a plain
            // urlencoded body, without hand-building MIME boundaries ourselves.
            $curl->setOption(CURLOPT_POSTFIELDS, [
                'advertiser_id' => $advertiserId,
                'file' => new \CURLFile($tmpFile, 'text/csv', 'ordo_audience.csv'),
                // TikTok's own API spec requires file_signature to be the file's literal MD5 -
                // a server-side integrity checksum the platform itself mandates, not a security
                // boundary this module controls or could swap for a stronger hash unilaterally.
                // phpcs:ignore Magento2.Security.InsecureFunction.Found
                'file_signature' => md5($fileContent),
                'calculate_type' => self::CALCULATE_TYPE,
            ]);
            $curl->post(self::API_BASE_URL . '/dmp/custom_audience/file/upload/', []);

            $status = $curl->getStatus();
            $body = (string) $curl->getBody();
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf(
                    'TikTok Marketing API file upload failed (HTTP %d): %s',
                    $status,
                    $body
                ));
            }

            $decoded = $this->assertSuccess(json_decode($body, true), 'file upload');
            $filePath = $this->extractDataField($decoded, 'file_path');
            if ($filePath === null) {
                throw new RuntimeException('TikTok file upload response had no data.file_path.');
            }

            return $filePath;
        } finally {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            unlink($tmpFile);
        }
    }

    private function createAudience(string $filePath, string $accessToken, string $advertiserId): string
    {
        $body = $this->request('/dmp/custom_audience/create/', $accessToken, [
            'advertiser_id' => $advertiserId,
            'custom_audience_name' => 'Ordo Automation Segment Audience',
            'calculate_type' => self::CALCULATE_TYPE,
            'file_paths' => [$filePath],
        ]);

        $audienceId = $this->extractDataField($body, 'custom_audience_id');
        if ($audienceId === null) {
            throw new RuntimeException('TikTok custom_audience/create response had no data.custom_audience_id.');
        }

        return $audienceId;
    }

    /**
     * action=REPLACE overwrites the audience's whole membership with this file - the documented
     * default, passed explicitly rather than relied on implicitly. TikTok itself refuses this
     * call if the audience would end up under 1,000 members - same "report failure honestly,
     * Cron\SyncAdAudiences is the one place that catches/logs/records it" contract every
     * SyncClientInterface implementation already follows, nothing special handled here.
     */
    private function replaceAudienceMembers(
        string $audienceId,
        string $filePath,
        string $accessToken,
        string $advertiserId
    ): void {
        $this->request('/dmp/custom_audience/update/', $accessToken, [
            'advertiser_id' => $advertiserId,
            'custom_audience_id' => $audienceId,
            'file_paths' => [$filePath],
            'action' => 'REPLACE',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $path, string $accessToken, array $payload): array
    {
        $body = $this->jsonApiClient->postJson(
            self::API_BASE_URL . $path,
            $payload,
            ['Access-Token' => $accessToken],
            'TikTok Marketing API'
        );

        return $this->assertSuccess($body, $path);
    }

    /**
     * TikTok's own API returns HTTP 200 for both success and failure - real errors only ever
     * show up in this envelope's own "code" field (0 = success), confirmed by a live
     * unauthenticated call that got a 200 response body carrying a real, specific error code.
     * JsonApiClient::postJson()'s HTTP-status check alone would have missed this entirely.
     *
     * @return array<string, mixed>
     */
    private function assertSuccess(mixed $decoded, string $context): array
    {
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('TikTok Marketing API %s response was not valid JSON.', $context));
        }

        $rawCode = $decoded['code'] ?? null;
        $code = is_numeric($rawCode) ? (int) $rawCode : null;
        if ($code !== null && $code !== 0) {
            $message = $decoded['message'] ?? null;
            throw new RuntimeException(sprintf(
                'TikTok Marketing API %s failed (code %d): %s',
                $context,
                $code,
                is_string($message) ? $message : 'no message'
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractDataField(array $body, string $key): ?string
    {
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
