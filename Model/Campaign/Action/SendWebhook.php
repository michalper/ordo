<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"event": "optional-override-name"} - "event" is a free-text label included in the
 * outbound payload as "event" (defaults to the campaign's own trigger_event from context when
 * omitted, e.g. "order_placed"); this action never needs a URL/secret of its own since those are
 * store-wide config (Helper\Config::getWebhookOutboundUrl()/getWebhookOutboundSecret()), same
 * "one endpoint for the whole store" shape send_sms/send_whatsapp use for their own providers.
 *
 * POSTs the full campaign/trigger context as JSON to the configured outbound URL, signed with
 * HMAC-SHA256 over the raw body (Model\Webhook\WebhookSignatureValidator::sign()), sent as the
 * "X-Ordo-Signature" header - so the receiving ERP/CRM/PIM can verify the call really came from
 * this store, the same scheme Controller\Webhook\Receive expects of an inbound caller.
 *
 * Deliberately does NOT check ConsentManager/QuietHoursGate/FrequencyCapGate - unlike
 * send_email/send_sms/send_whatsapp/send_push this isn't a message to a customer's own inbox/
 * phone, it's a system-to-system integration call, so those customer-communication gates don't
 * apply here.
 */
class SendWebhook implements ActionInterface
{
    private const string ACTION_TYPE = 'send_webhook';

    public function __construct(
        private readonly Config $config,
        private readonly JsonApiClient $jsonApiClient,
        private readonly WebhookSignatureValidator $signatureValidator,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly SendRetrier $sendRetrier,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        if (!$this->config->isWebhookEnabled()) {
            $this->logger->debug(
                'Ordo_Automation: send_webhook action skipped, webhooks are disabled in config.'
            );
            return;
        }

        $url = $this->config->getWebhookOutboundUrl();
        if ($url === '') {
            $this->logger->error('Ordo_Automation: send_webhook action skipped, no Outbound URL is configured.');
            return;
        }

        $secret = $this->config->getWebhookOutboundSecret();
        if ($secret === '') {
            $this->logger->error(
                'Ordo_Automation: send_webhook action skipped, no Outbound Signing Secret is configured.'
            );
            return;
        }

        $event = trim((string) ($params['event'] ?? ''));
        if ($event === '') {
            $event = (string) ($context['trigger_event'] ?? 'campaign_action');
        }

        $payload = [
            'event' => $event,
            'campaign_id' => isset($context['campaign_id']) ? (int) $context['campaign_id'] : null,
            'context' => $context,
        ];

        try {
            $this->sendRetrier->attempt(fn () => $this->post($url, $secret, $payload));
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_webhook action failed to reach "%s": %s',
                $url,
                $e->getMessage()
            ));

            // See SendEmail's identical block / MessageSendRetryQueue::RETRY_CONTEXT_FLAG's own
            // docblock for why this rethrows on a retry attempt instead of enqueuing again.
            if (!empty($context[MessageSendRetryQueue::RETRY_CONTEXT_FLAG])) {
                throw $e;
            }
            $this->messageSendRetryQueue->enqueue(self::ACTION_TYPE, $context, $params, $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $url, string $secret, array $payload): void
    {
        $this->rateLimiter->throttle('webhook', (float) $this->config->getWebhookMaxRequestsPerSecond());

        $rawBody = (string) json_encode($payload);
        $signature = $this->signatureValidator->sign($secret, $rawBody);

        $this->jsonApiClient->postJson(
            $url,
            $payload,
            [WebhookSignatureValidator::SIGNATURE_HEADER => $signature],
            'Ordo webhook'
        );
    }
}
