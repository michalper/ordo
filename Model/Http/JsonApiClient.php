<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Http;

use Magento\Framework\HTTP\Client\Curl;
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
 */
class JsonApiClient
{
    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Curl $curl
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
        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->addHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $this->curl->addHeader($name, $value);
        }
        $this->curl->post($url, (string) json_encode($payload));

        return $this->interpretResponse($url, $serviceName);
    }

    /**
     * GET counterpart to postJson() — same mechanics, no body.
     *
     * @param array<string, string> $headers
     * @return array<mixed>
     */
    public function getJson(string $url, array $headers, string $serviceName): array
    {
        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        foreach ($headers as $name => $value) {
            $this->curl->addHeader($name, $value);
        }
        $this->curl->get($url);

        return $this->interpretResponse($url, $serviceName);
    }

    /**
     * @return array<mixed>
     */
    private function interpretResponse(string $url, string $serviceName): array
    {
        $status = $this->curl->getStatus();
        $responseBody = (string) $this->curl->getBody();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                sprintf('%s request to %s failed (HTTP %d): %s', $serviceName, $url, $status, $responseBody)
            );
        }

        $decoded = json_decode($responseBody, true);
        return is_array($decoded) ? $decoded : [];
    }
}
