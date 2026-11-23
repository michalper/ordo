<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Push\PushEndpointValidator;
use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;
use Psr\Log\LoggerInterface;

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
 * this store, the same scheme Controller\Webhook\Receive expects of an inbound caller. The actual
 * throttle/retry/log-and-enqueue-for-retry tail is OutboundConversionApiSender's, same as
 * SendGa4Event/SendMetaConversionEvent - this action's own custom signature header is what used
 * to block it from reusing that class, until that class grew an optional $headers parameter.
 *
 * Deliberately does NOT check ConsentManager/QuietHoursGate/FrequencyCapGate - unlike
 * send_email/send_sms/send_whatsapp/send_push this isn't a message to a customer's own inbox/
 * phone, it's a system-to-system integration call, so those customer-communication gates don't
 * apply here.
 *
 * The Outbound URL IS validated, though, the same way Model\Push\PushEndpointValidator already
 * guards a customer-supplied push endpoint - re-validated here, not just when the config is
 * saved, since DNS for an otherwise-legitimate host could be repointed at an internal address in
 * the meantime (rebinding). Without this, an admin-configured (or, via a compromised admin
 * account, attacker-configured) Outbound URL pointing at an internal-only host or a cloud
 * metadata endpoint (169.254.169.254) would get a real server-initiated HTTP request every time
 * this action fires - a textbook SSRF, found during a full-repo security review. The validator's
 * own name/namespace predates this use (it was written for push subscriptions first) but its
 * actual logic - resolve a hostname, require every resolved address to be public, pin the real
 * connection to the validated IP - is entirely generic; reused as-is rather than duplicating it a
 * third time (Model\ContentBlock\RssFetcher has its own, separate copy - a known, tracked cleanup
 * item, not fixed here to keep this change scoped to the actual vulnerability).
 */
class SendWebhook implements ActionInterface
{
    private const string ACTION_TYPE = 'send_webhook';
    private const string RATE_LIMIT_CHANNEL = 'webhook';

    public function __construct(
        private readonly Config $config,
        private readonly WebhookSignatureValidator $signatureValidator,
        private readonly OutboundConversionApiSender $sender,
        private readonly PushEndpointValidator $endpointValidator,
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

        $pinnedIp = $this->endpointValidator->resolvePinnedIp($url);
        if ($pinnedIp === null) {
            $this->logger->error(
                'Ordo_Automation: send_webhook action skipped, the configured Outbound URL failed '
                . 'validation (must be HTTPS and resolve only to a public address).'
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

        $signature = $this->signatureValidator->sign($secret, (string) json_encode($payload));

        $this->sender->send(
            self::ACTION_TYPE,
            self::RATE_LIMIT_CHANNEL,
            (float) $this->config->getWebhookMaxRequestsPerSecond(),
            $url,
            $payload,
            'Ordo webhook',
            $context,
            $params,
            headers: [WebhookSignatureValidator::SIGNATURE_HEADER => $signature],
            pinnedIp: $pinnedIp
        );
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'event',
                'label' => (string) __('Event name override (optional)'),
                'notice' => (string) __(
                    'Defaults to the campaign\'s own trigger event (e.g. "order_placed") when left blank.'
                ),
            ],
        ];
    }
}
