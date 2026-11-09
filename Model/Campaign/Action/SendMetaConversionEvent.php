<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AdAudience\PiiHasher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports a real order to Meta's Conversions API
 * (https://developers.facebook.com/docs/marketing-api/conversions-api), server to server - same
 * reasoning as the sibling Model\Campaign\Action\SendGa4Event for why this is its own action
 * rather than built on Model\Campaign\Action\SendWebhook: Meta's API needs its own payload shape,
 * its own pixel_id/access_token auth (as query params, not this store's HMAC-signed webhook
 * scheme), and - unlike GA4 - a hashed customer identifier for user matching, which a generic
 * webhook payload has no concept of at all.
 *
 * Deliberately independent of the existing Ad Audience Sync integration
 * (Model\AdAudience\MetaSyncClient/Helper\Config::getMetaAccessToken()) even though both talk to
 * Meta: that one pushes whole customer lists for ad *targeting* (Custom Audiences), this reports
 * one purchase at a time for ad *measurement/optimization* (Conversions API) - different Meta
 * products, different credentials, so this gets its own Pixel ID/access token fields rather than
 * silently reusing the ad-audience-sync ones (which may not even have the right permission scope
 * for this different API).
 *
 * Reuses Model\AdAudience\PiiHasher::hashEmail() - Meta's own hashing spec for Conversions API's
 * "em" field is identical to Custom Audiences' (trim, lowercase, SHA-256 hex), so this is the
 * same one shared chokepoint that class's own docblock already establishes, not a second,
 * easy-to-get-subtly-wrong copy of the same normalization.
 *
 * Context must include "order_id" and "customer_id" (the latter to resolve a real email to
 * hash - Meta's own matching needs it, GA4's sibling action has no equivalent requirement).
 * Fails closed when either is missing/unresolvable, same as every other action in this module.
 */
class SendMetaConversionEvent implements ActionInterface
{
    private const string ACTION_TYPE = 'send_meta_conversion_event';
    private const string GRAPH_API_VERSION = 'v21.0';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderContextResolver $orderContextResolver,
        private readonly PiiHasher $piiHasher,
        private readonly OutboundConversionApiSender $sender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        if (!$this->config->isMetaCapiEnabled()) {
            $this->logger->debug(
                'Ordo_Automation: send_meta_conversion_event action skipped, Meta CAPI reporting is disabled.'
            );
            return;
        }

        $pixelId = $this->config->getMetaCapiPixelId();
        $accessToken = $this->config->getMetaCapiAccessToken();
        if ($pixelId === '' || $accessToken === '') {
            $this->logger->error(
                'Ordo_Automation: send_meta_conversion_event action skipped, Pixel ID/Access Token is not'
                . ' configured.'
            );
            return;
        }

        $order = $this->orderContextResolver->resolveOrder($context);
        if (!$order instanceof OrderInterface) {
            $this->logger->error(
                'Ordo_Automation: send_meta_conversion_event action skipped, context has no loadable order_id.'
            );
            return;
        }

        $email = $this->resolveCustomerEmail($context);
        if ($email === null) {
            $this->logger->error(
                'Ordo_Automation: send_meta_conversion_event action skipped, context has no loadable'
                . ' customer_id/email.'
            );
            return;
        }

        $rawEventName = $params['event_name'] ?? '';
        $eventName = is_string($rawEventName) ? trim($rawEventName) : '';
        $eventName = $eventName !== '' ? $eventName : 'Purchase';

        $payload = [
            'data' => [
                [
                    'event_name' => $eventName,
                    'event_time' => time(),
                    'action_source' => 'website',
                    'user_data' => [
                        'em' => [$this->piiHasher->hashEmail($email)],
                    ],
                    'custom_data' => [
                        'value' => (float) $order->getGrandTotal(),
                        'currency' => strtolower((string) $order->getOrderCurrencyCode()),
                    ],
                ],
            ],
        ];

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events?%s',
            self::GRAPH_API_VERSION,
            $pixelId,
            http_build_query(['access_token' => $accessToken])
        );

        $this->sender->send(
            self::ACTION_TYPE,
            'meta_conversion_event',
            (float) $this->config->getWebhookMaxRequestsPerSecond(),
            $url,
            $payload,
            'Meta Conversions API',
            $context,
            $params,
            ['access_token']
        );
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'event_name',
                'label' => (string) __('Meta event name (optional)'),
                'notice' => (string) __(
                    'Defaults to "Purchase" (one of Meta\'s own standard event names) when left blank.'
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveCustomerEmail(array $context): ?string
    {
        $rawCustomerId = $context['customer_id'] ?? 0;
        $customerId = is_int($rawCustomerId) ? $rawCustomerId : 0;
        if ($customerId <= 0) {
            return null;
        }

        try {
            $email = $this->customerRepository->getById($customerId)->getEmail();
        } catch (Throwable) {
            return null;
        }

        return $email !== '' ? $email : null;
    }
}
