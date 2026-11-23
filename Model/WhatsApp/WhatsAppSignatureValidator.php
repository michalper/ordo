<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\WhatsApp;

use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;

/**
 * Meta's own documented webhook signature scheme (shared across all Meta platform webhooks,
 * WhatsApp included): HMAC-SHA256 over the raw request body, keyed by the app's own App Secret,
 * sent as the "X-Hub-Signature-256" header in the form "sha256=<hex digest>" -
 * byte-for-byte the same shape Model\Webhook\WebhookSignatureValidator already implements for
 * this module's own outbound/inbound webhook signing, so this delegates to it rather than
 * re-verifying with a second, separately-maintained copy of the same HMAC comparison (found via
 * a full-repo review). Kept as its own class, not inlined at the one call site
 * (Controller\WhatsApp\Webhook), for the same "small, dedicated collaborator so the controller
 * reads as verify-then-process" reasoning as Sms\CallbackUrlBuilder/Email\SendGridSignatureValidator
 * - and because "app secret" and "this module's own webhook secret" are conceptually distinct
 * even though the underlying crypto happens to be identical.
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started#validating-payloads
 */
class WhatsAppSignatureValidator
{
    public function __construct(
        private readonly WebhookSignatureValidator $webhookSignatureValidator
    ) {
    }

    public function isValid(string $appSecret, string $rawBody, string $signatureHeader): bool
    {
        return $this->webhookSignatureValidator->isValid($appSecret, $rawBody, $signatureHeader);
    }
}
