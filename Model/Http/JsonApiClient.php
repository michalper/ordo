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

    public function __construct(
        private readonly CurlFactory $curlFactory
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers Extra headers beyond Content-Type: application/json
     *   (e.g. Authorization) - caller-specific, so not baked in here.
     * @param string $serviceName Named in the exception message on failure, e.g. "Google Ads API".
     * @return array<mixed> the decoded JSON response body, or [] if it wasn't a JSON object/array.
     */
    public function postJson(string $url, array $payload, array $headers, string $serviceName): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->addHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $curl->addHeader($name, $value);
        }
        $curl->post($url, (string) json_encode($payload));

        return $this->interpretResponse($curl, $url, $serviceName);
    }

    /**
     * GET counterpart to postJson() — same mechanics, no body.
     *
     * @param array<string, string> $headers
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
     * @return array<mixed>
     */
    private function interpretResponse(Curl $curl, string $url, string $serviceName): array
    {
        $status = $curl->getStatus();
        $responseBody = (string) $curl->getBody();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                sprintf('%s request to %s failed (HTTP %d): %s', $serviceName, $url, $status, $responseBody)
            );
        }

        $decoded = json_decode($responseBody, true);
        return is_array($decoded) ? $decoded : [];
    }
}
