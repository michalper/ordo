<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Http;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

/**
 * The identical "POST JSON, check status, decode JSON response" shape that used to be
 * copy-pasted, byte for byte except the auth header, across Model\AdAudience\GoogleAdsSyncClient,
 * Model\AdAudience\MetaSyncClient, and Model\WhatsApp\WhatsAppSender - each of those still owns
 * its own request-building (URL, payload shape, which headers to add) and response-interpreting
 * (which field means success/partial failure) logic, since that genuinely differs per API; only
 * the HTTP mechanics underneath were duplicated. Model\Push\PushSender is deliberately NOT built
 * on this - its payload is pre-encrypted binary (Content-Type: application/octet-stream, not
 * JSON), so forcing it through a "postJson" abstraction would be the wrong fit.
 *
 * Builds a fresh Curl instance per call via CurlFactory rather than injecting one Curl - Curl
 * never clears headers it was told to add, and this module's own DI never declares it
 * shared="false", so a single shared instance would carry one caller's Authorization/developer-
 * token headers into the next, unrelated caller's request within the same process (confirmed the
 * hard way: Cron\SyncAdAudiences calls both GoogleAdsSyncClient and MetaSyncClient in the same
 * execute(), and the latter's postJson() call passes no auth headers of its own, relying on the
 * access_token already being in its own POST body - meaning a shared Curl would still be
 * carrying Google's bearer/developer-token headers onto the request to Meta's API).
 */
class JsonApiClient
{
    private const int TIMEOUT_SECONDS = 30;

    // Same reasoning/value as Model\Push\PushSender's own cap: an arbitrary host's response text
    // ending up verbatim in this module's own logs (OutboundConversionApiSender's logger->error())
    // and the persisted ordo_message_send_retry.last_error column would be an information
    // disclosure amplifier if that host happens to be an internal service reached via SSRF.
    private const int MAX_LOGGED_RESPONSE_LENGTH = 200;

    public function __construct(
        private readonly CurlFactory $curlFactory
    ) {
    }

    /**
     * POSTs $payload as JSON and decodes the response.
     *
     * @param string $url The real request URL - the full one, including any auth query params;
     *   see $redactQueryParams below for how those stay out of a failure message.
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers Extra headers beyond Content-Type: application/json
     *   (e.g. Authorization) - caller-specific, so not baked in here.
     * @param string $serviceName Named in the exception message on failure, e.g. "Google Ads API".
     * @param string[] $redactQueryParams Names of $url query params to mask (as "[redacted]")
     *   before building the on-failure exception message - for the rare caller (SendGa4Event,
     *   SendMetaConversionEvent) whose own API requires its auth secret in the URL itself rather
     *   than a header; without this, a live api_secret/access_token would otherwise end up
     *   verbatim in that exception's message, and from there in both the application log
     *   (OutboundConversionApiSender's logger->error()) and the persisted
     *   ordo_message_send_retry.last_error column. Does not affect the real request, which still
     *   uses the full, unredacted $url.
     * @param string|null $pinnedIp When the destination URL's host was resolved and validated by
     *   the caller (e.g. SendWebhook, whose Outbound URL is admin-configurable and therefore a
     *   real SSRF surface, unlike GA4/Meta's own fixed API hosts), the exact IP that validation
     *   resolved to - pins the real curl connection to it via CURLOPT_RESOLVE so this client's own,
     *   independent DNS resolution for $url can never land on a different (e.g. internal) address
     *   than the one just validated (DNS rebinding). Only meaningful together with a caller that
     *   actually validates $url's host first; this parameter does not perform any validation
     *   itself. See Model\Push\PushEndpointValidator::resolvePinnedIp()'s own docblock for the
     *   full reasoning - this is the same pattern Model\Push\PushSender already applies.
     * @return array<mixed> the decoded JSON response body, or [] if it wasn't a JSON object/array.
     */
    public function postJson(
        string $url,
        array $payload,
        array $headers,
        string $serviceName,
        array $redactQueryParams = [],
        ?string $pinnedIp = null
    ): array {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        if ($pinnedIp !== null) {
            $this->pinConnection($curl, $url, $pinnedIp);
        }
        $curl->addHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $curl->addHeader($name, $value);
        }
        $curl->post($url, (string) json_encode($payload));

        return $this->interpretResponse($curl, $url, $serviceName, $redactQueryParams);
    }

    /**
     * Pins $curl's connection for $url's host to $pinnedIp via CURLOPT_RESOLVE - see postJson()'s
     * own $pinnedIp docblock for why. $curl here is always a fresh instance from CurlFactory
     * (never shared/reused across calls, unlike PushSender's own Curl), so there is no equivalent
     * need to remove the pinned entry afterward.
     */
    private function pinConnection(Curl $curl, string $url, string $pinnedIp): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $host = (string) parse_url($url, PHP_URL_HOST);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $curl->setOption(CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $host, $port, $pinnedIp)]);
    }

    /**
     * GET counterpart to postJson() — same mechanics, no body.
     *
     * @param string $url The real request URL.
     * @param array<string,string> $headers
     * @param string $serviceName Named in the exception message on failure.
     * @return array<mixed>
     */
    public function getJson(string $url, array $headers, string $serviceName): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        foreach ($headers as $name => $value) {
            $curl->addHeader($name, $value);
        }
        $curl->get($url);

        return $this->interpretResponse($curl, $url, $serviceName);
    }

    /**
     * Checks the real HTTP status and decodes the response body.
     *
     * @param Curl $curl The real, already-dispatched request this call is reading a response from.
     * @param string $url Passed through only to build the failure message, redacted below.
     * @param string $serviceName Named in the exception message on failure.
     * @param string[] $redactQueryParams
     * @return array<mixed>
     */
    private function interpretResponse(
        Curl $curl,
        string $url,
        string $serviceName,
        array $redactQueryParams = []
    ): array {
        $status = $curl->getStatus();
        $responseBody = (string) $curl->getBody();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(
                '%s request to %s failed (HTTP %d): %s',
                $serviceName,
                $this->redactUrl($url, $redactQueryParams),
                $status,
                substr($responseBody, 0, self::MAX_LOGGED_RESPONSE_LENGTH)
            ));
        }

        $decoded = json_decode($responseBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Masks the given query-param names in a copy of $url, for a failure message only.
     *
     * Never used for the real outbound request itself - that still uses the full $url.
     *
     * @param string $url The full, unredacted URL to mask a copy of.
     * @param string[] $redactQueryParams
     */
    private function redactUrl(string $url, array $redactQueryParams): string
    {
        if ($redactQueryParams === []) {
            return $url;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return $url;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        parse_str($query, $queryParams);
        foreach ($redactQueryParams as $param) {
            if (array_key_exists($param, $queryParams)) {
                $queryParams[$param] = '[redacted]';
            }
        }

        return str_replace($query, http_build_query($queryParams), $url);
    }
}
