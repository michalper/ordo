<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports a real order to Google Analytics 4's Measurement Protocol
 * (https://developers.google.com/analytics/devguides/collection/protocol/ga4), server to server -
 * NOT built on Model\Campaign\Action\SendWebhook, even though both end in an outbound HTTP POST:
 * that action always wraps its payload as {"event", "campaign_id", "context"}, always signs it
 * with this store's own HMAC secret, and always posts to one store-wide configured URL - none of
 * which GA4's own API accepts. This action builds GA4's own exact payload shape and posts
 * directly to GA4's own endpoint with GA4's own auth (measurement_id + api_secret as query
 * params), so a store's existing GA4 property gets real purchase data even when the customer's
 * browser never actually loaded/ran the regular on-page gtag.js tag (an ad blocker, Safari ITP,
 * or a declined cookie consent banner all silently drop that regular client-side hit).
 *
 * Context must include "order_id" - intended for the "order_placed" trigger (or any other
 * trigger/condition chain a store builds that still has a real order in context, e.g.
 * "order_placed" + a value condition, per ROADMAP.md's own framing of this gap). Fails closed
 * (logs, does not throw) when context has no usable order_id, same as every other action in this
 * module when its own required context is missing.
 *
 * "client_id" has no real browser/cookie value available server-side - GA4 itself documents that
 * a stable, unique string is an acceptable stand-in for a server-to-server hit with no existing
 * web session to attach to, so this uses "ordo-customer-<id>". Honestly: this means the event
 * will NOT be stitched to whatever ad click/session actually drove that customer's visit unless
 * the store's own GA4 property has User-ID tracking already linking that customer_id to real
 * browser sessions - this action reports the conversion itself, it does not solve attribution
 * modeling on its own.
 */
class SendGa4Event implements ActionInterface
{
    private const string ACTION_TYPE = 'send_ga4_event';
    private const string ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    public function __construct(
        private readonly Config $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly JsonApiClient $jsonApiClient,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly SendRetrier $sendRetrier,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        if (!$this->config->isGa4Enabled()) {
            $this->logger->debug('Ordo_Automation: send_ga4_event action skipped, GA4 reporting is disabled.');
            return;
        }

        $measurementId = $this->config->getGa4MeasurementId();
        $apiSecret = $this->config->getGa4ApiSecret();
        if ($measurementId === '' || $apiSecret === '') {
            $this->logger->error(
                'Ordo_Automation: send_ga4_event action skipped, GA4 Measurement ID/API Secret is not configured.'
            );
            return;
        }

        $order = $this->resolveOrder($context);
        if ($order === null) {
            $this->logger->error(
                'Ordo_Automation: send_ga4_event action skipped, context has no loadable order_id.'
            );
            return;
        }

        $rawCustomerId = $context['customer_id'] ?? 0;
        $customerId = is_int($rawCustomerId) ? $rawCustomerId : 0;

        $rawEventName = $params['event_name'] ?? '';
        $eventName = is_string($rawEventName) ? trim($rawEventName) : '';
        $eventName = $eventName !== '' ? $eventName : 'purchase';

        $payload = [
            'client_id' => 'ordo-customer-' . $customerId,
            'events' => [
                [
                    'name' => $eventName,
                    'params' => [
                        'transaction_id' => (string) $order->getIncrementId(),
                        'value' => (float) $order->getGrandTotal(),
                        'currency' => (string) $order->getOrderCurrencyCode(),
                    ],
                ],
            ],
        ];

        $url = self::ENDPOINT . '?' . http_build_query([
            'measurement_id' => $measurementId,
            'api_secret' => $apiSecret,
        ]);

        try {
            $this->rateLimiter->throttle('ga4_event', (float) $this->config->getWebhookMaxRequestsPerSecond());
            $this->sendRetrier->attempt(
                fn () => $this->jsonApiClient->postJson($url, $payload, [], 'GA4 Measurement Protocol')
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: send_ga4_event action failed to reach GA4: %s',
                $e->getMessage()
            ));
            $this->messageSendRetryQueue->enqueueOrRethrow(self::ACTION_TYPE, $context, $params, $e);
        }
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'event_name',
                'label' => (string) __('GA4 event name (optional)'),
                'notice' => (string) __(
                    'Defaults to "purchase" (GA4\'s own recommended event name for this) when left blank.'
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveOrder(array $context): ?OrderInterface
    {
        $rawOrderId = $context['order_id'] ?? 0;
        $orderId = is_int($rawOrderId) ? $rawOrderId : 0;
        if ($orderId <= 0) {
            return null;
        }

        try {
            return $this->orderRepository->get($orderId);
        } catch (Throwable) {
            return null;
        }
    }
}
