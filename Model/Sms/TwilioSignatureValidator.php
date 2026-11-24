<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

use Twilio\Security\RequestValidator;

/**
 * Twilio's own documented webhook signature scheme: HMAC-SHA1 over the callback URL + sorted POST
 * params, keyed by the account's Auth Token, sent as the "X-Twilio-Signature" header -
 * Controller\Sms\Reply and Controller\Sms\StatusCallback each independently repeated the exact
 * same verbatim block (read the header/POST params, the empty-auth-token fail-closed guard, and
 * the RequestValidator construct+validate call), found hand-duplicated during a full-repo
 * /simplify sweep. Mirrors Model\WhatsApp\WhatsAppSignatureValidator's own "small, dedicated
 * collaborator" shape.
 */
class TwilioSignatureValidator
{
    /**
     * @param array<mixed> $params
     */
    public function isValid(string $authToken, string $signature, string $url, array $params): bool
    {
        // Twilio's RequestValidator has no special-case rejection for an empty *key* (only for an
        // empty signature) - with no auth token configured yet, the HMAC key is a known constant
        // ('') and the correct signature is fully computable by anyone from the public callback
        // URL + POST params, same fail-open risk SendGridSignatureValidator::isValid() guards
        // against for its own empty key. Must fail closed here before ever constructing/calling
        // RequestValidator.
        if ($authToken === '' || $signature === '') {
            return false;
        }

        return (new RequestValidator($authToken))->validate($signature, $url, $params);
    }
}
