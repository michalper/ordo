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
        // server, so it's the one that must never be skipped.
        if (!$this->pushEndpointValidator->isAllowed($endpoint)) {
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

        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->addHeader('Content-Type', 'application/octet-stream');
        $this->curl->addHeader('Content-Encoding', 'aes128gcm');
        $this->curl->addHeader('TTL', (string) self::TTL_SECONDS);
        $this->curl->addHeader('Authorization', $authorization);
        $this->curl->post($endpoint, $body);

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
