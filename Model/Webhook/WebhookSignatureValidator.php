<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Webhook;

/**
 * This module's own outbound/inbound webhook signing scheme (Model\Campaign\Action\SendWebhook /
 * Controller\Webhook\Receive): HMAC-SHA256 over the raw request body, keyed by a per-store secret
 * (Helper\Config::getWebhookOutboundSecret()/getWebhookInboundSecret()), sent/expected as the
 * "X-Ordo-Signature" header in the form "sha256=<hex digest>" - the same shape Meta's own
 * X-Hub-Signature-256 uses (see Model\WhatsApp\WhatsAppSignatureValidator), so a receiver that
 * already knows how to verify Meta's webhooks can verify this module's outbound calls the same
 * way, and vice versa for what this module's own inbound endpoint expects from an external
 * ERP/CRM/PIM.
 */
class WebhookSignatureValidator
{
    public const string SIGNATURE_HEADER = 'X-Ordo-Signature';
    private const string SIGNATURE_PREFIX = 'sha256=';

    public function sign(string $secret, string $rawBody): string
    {
        return self::SIGNATURE_PREFIX . hash_hmac('sha256', $rawBody, $secret);
    }

    public function isValid(string $secret, string $rawBody, string $signatureHeader): bool
    {
        if ($secret === '' || !str_starts_with($signatureHeader, self::SIGNATURE_PREFIX)) {
            return false;
        }

        $provided = substr($signatureHeader, strlen(self::SIGNATURE_PREFIX));
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }
}
