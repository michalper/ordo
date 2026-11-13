<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Email;

use Magento\Framework\Encryption\EncryptorInterface;
use Ordo\Automation\Model\ConsentChannel;

/**
 * Stateless, no-DB-lookup unsubscribe link token for the no-login one-click unsubscribe endpoint
 * (Controller\Email\Unsubscribe) - see docs/adr/0055-email-one-click-unsubscribe.md for why this
 * is a signed, deterministic token rather than a stored-per-row one like
 * Model\Approval\ApprovalTokenGenerator's order-approval token.
 *
 * Shape: "<customerId>.<channel>.<signature>" - the customerId/channel are carried in plaintext
 * alongside an HMAC-SHA256 signature over them (the same "claims in plaintext + a signature"
 * shape a JWT uses), signed with EncryptorInterface::hash() - Magento's own hash_hmac() helper,
 * keyed by this installation's own crypt key (app/etc/env.php). That key never leaves this
 * server and is already guaranteed to exist on every Magento install, so this needs no new
 * admin-configurable secret the way Model\Webhook\WebhookSignatureValidator/
 * Model\WhatsApp\WhatsAppSignatureValidator do for their externally-shared secrets - one-click
 * unsubscribe works immediately on a fresh install with zero configuration, which matters since
 * this closes a bulk-sender compliance requirement that should hold by default, not after an
 * admin discovers a new settings field.
 *
 * A customer id is not confidential (same reasoning a JWT's own claims aren't), so putting it in
 * the clear here is fine - the signature is what prevents forging a token for a DIFFERENT
 * customer or channel than the one it was actually issued for.
 */
class UnsubscribeTokenManager
{
    private const string DELIMITER = '.';

    public function __construct(
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function generate(int $customerId, ConsentChannel $channel): string
    {
        return $customerId . self::DELIMITER . $channel->value . self::DELIMITER . $this->sign($customerId, $channel);
    }

    /**
     * Returns null for anything that doesn't parse as "<int>.<channel>.<signature>" or whose
     * signature doesn't match the claimed customerId/channel - deliberately the same failure
     * shape for "malformed" and "tampered", so a forged token can't be told apart from a typo.
     */
    public function resolve(string $token): ?UnsubscribeToken
    {
        $parts = explode(self::DELIMITER, $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$customerIdPart, $channelValue, $signature] = $parts;
        if ($customerIdPart === '' || !ctype_digit($customerIdPart)) {
            return null;
        }

        $channel = ConsentChannel::tryFrom($channelValue);
        if ($channel === null) {
            return null;
        }

        $customerId = (int) $customerIdPart;
        if (!hash_equals($this->sign($customerId, $channel), $signature)) {
            return null;
        }

        return new UnsubscribeToken($customerId, $channel);
    }

    private function sign(int $customerId, ConsentChannel $channel): string
    {
        return $this->encryptor->hash($customerId . '|' . $channel->value);
    }
}
