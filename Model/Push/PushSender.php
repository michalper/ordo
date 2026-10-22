<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Push;

use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Push\Exception\SubscriptionGoneException;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use RuntimeException;

/**
 * Sends a single Web Push message to one subscription - real HTTP to whatever push service that
 * subscription's endpoint points at (FCM, Mozilla autopush, etc.), no vendor SDK, same choice as
 * Model\WhatsApp\WhatsAppSender/Model\Sms\TwilioSmsSender.
 */
class PushSender
{
    private const int TIMEOUT_SECONDS = 30;
    private const int TTL_SECONDS = 4 * 3600;
    private const int MAX_LOGGED_RESPONSE_LENGTH = 200;

    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly VapidTokenBuilder $vapidTokenBuilder,
        private readonly WebPushCrypto $webPushCrypto,
        private readonly PushEndpointValidator $pushEndpointValidator,
        private readonly OutboundRateLimiter $rateLimiter
    ) {
    }

    /**
     * @param string $payloadJson The notification payload, plaintext JSON - push-sw.js's own
     *   `push` event handler is what actually reads title/body/url out of this.
     * @throws SubscriptionGoneException the push service reports this subscription no longer
     *   exists (HTTP 404/410) - caller should delete it.
     */
    public function send(PushSubscription $subscription, string $payloadJson): void
    {
        $endpoint = $subscription->getEndpoint();

        // Re-validated here, not just at registration time (Controller\Track\
        // RegisterPushSubscription) - a campaign can fire long after a subscription was stored,
        // and DNS for an otherwise-legitimate-looking hostname could be repointed at an internal
        // address in the meantime (DNS rebinding). This is the request that actually leaves the
        // server, so it's the one that must never be skipped. resolvePinnedIp() (not isAllowed())
        // because the IP validated here must be the exact one curl connects to below, via
        // CURLOPT_RESOLVE - otherwise curl's own, independent DNS lookup for $endpoint could
        // still land on a different (internal) address than the one just validated, defeating
        // this check for a rebinding host with a short-TTL record that changes between the two.
        $pinnedIp = $this->pushEndpointValidator->resolvePinnedIp($endpoint);
        if ($pinnedIp === null) {
            throw new RuntimeException(
                sprintf(
                    'Push subscription #%d has an endpoint that failed validation.',
                    (int) $subscription->getEntityId()
                )
            );
        }

        $body = $this->webPushCrypto->encrypt(
            $payloadJson,
            $subscription->getP256dhKey(),
            $subscription->getAuthKey()
        );

        $authorization = $this->vapidTokenBuilder->buildAuthorizationHeader(
            $endpoint,
            $this->config->getVapidPublicKey(),
            $this->config->getVapidPrivateKey(),
            $this->config->getVapidSubject()
        );

        $this->rateLimiter->throttle('push', (float) $this->config->getPushMaxRequestsPerSecond());

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $port = parse_url($endpoint, PHP_URL_PORT) ?? 443;
        $resolveEntry = sprintf('%s:%d:%s', $host, $port, $pinnedIp);

        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        // Pins the connection to the exact IP just validated above - the curl client's own DNS
        // resolution for $endpoint is never consulted, so it can't resolve $host differently
        // (same hostname, different answer) between the validation above and this request.
        $this->curl->setOption(CURLOPT_RESOLVE, [$resolveEntry]);
        $this->curl->addHeader('Content-Type', 'application/octet-stream');
        $this->curl->addHeader('Content-Encoding', 'aes128gcm');
        $this->curl->addHeader('TTL', (string) self::TTL_SECONDS);
        $this->curl->addHeader('Authorization', $authorization);

        try {
            $this->curl->post($endpoint, $body);
        } finally {
            // This Curl client is a shared instance (reused across every subscription a campaign
            // action sends to in the same request) - the pinned entry above must not leak into
            // the next, unrelated endpoint's resolution. A "-host:port" entry removes it.
            $this->curl->setOption(CURLOPT_RESOLVE, [sprintf('-%s:%d', $host, $port)]);
        }

        $status = $this->curl->getStatus();
        if ($status === 404 || $status === 410) {
            throw new SubscriptionGoneException(
                sprintf('Push subscription #%d is gone (HTTP %d).', (int) $subscription->getEntityId(), $status)
            );
        }
        if ($status < 200 || $status >= 300) {
            // Deliberately not including the response body verbatim - $endpoint is
            // customer-controlled input (see PushEndpointValidator), so an arbitrary host's
            // response text ending up in this module's own logs would be an information
            // disclosure amplifier for whatever that host returns. A short, length-capped
            // snippet is enough to diagnose real push-service errors without that risk.
            throw new RuntimeException(sprintf(
                'Push service request failed (HTTP %d): %s',
                $status,
                substr((string) $this->curl->getBody(), 0, self::MAX_LOGGED_RESPONSE_LENGTH)
            ));
        }
    }
}
