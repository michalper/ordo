<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Wallet;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\Push\BaseSixtyFourUrl;
use RuntimeException;

/**
 * Builds and RS256-signs the "Save to Google Wallet" JWT
 * (https://developers.google.com/wallet/generic/use-cases/jwt) and returns the full
 * `https://pay.google.com/gp/v/save/<jwt>` URL. No vendor Google API client library - PHP's own
 * openssl_sign()/openssl_pkey_get_private() cover the whole thing, the same "no SDK, build the
 * protocol" precedent Model\Push\VapidTokenBuilder already sets for Web Push's own JWT.
 *
 * Simpler than VapidTokenBuilder: a Google service account's private key already comes as a
 * PKCS#8 PEM straight out of the downloaded JSON key file, so there's no DER hand-rolling needed
 * the way VapidTokenBuilder needs Model\Push\Der for a raw EC scalar -
 * openssl_pkey_get_private() loads a PEM private key directly, and RS256 signing/verification is
 * natively understood by every JWT consumer (Google's own included), unlike VAPID's ES256, which
 * needs the DER-to-raw-r||s conversion Der::parseEcdsaSignature() exists for.
 */
class GoogleWalletJwtBuilder
{
    private const string SAVE_URL_BASE = 'https://pay.google.com/gp/v/save/';

    public function __construct(
        private readonly DateTime $dateTime,
        private readonly BaseSixtyFourUrl $base64Url
    ) {
    }

    /**
     * @param array<string, mixed> $loyaltyObject GoogleWalletLoyaltyObjectBuilder::build()'s output.
     * @param string $serviceAccountEmail The "client_email" field of the Google service account's
     *   downloaded JSON key - becomes the JWT's `iss` claim.
     * @param string $privateKeyPem The matching "private_key" field (PEM) from the same JSON key -
     *   signs the JWT. Never sent anywhere.
     * @return string The full "https://pay.google.com/gp/v/save/<jwt>" URL - redirecting a
     *   browser to it (or using it as a link's href) is Google's own documented way to hand a
     *   user a wallet pass.
     */
    public function buildSaveUrl(array $loyaltyObject, string $serviceAccountEmail, string $privateKeyPem): string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new RuntimeException('Failed to load Google Wallet service account private key.');
        }

        $header = $this->base64Url->encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64Url->encode((string) json_encode([
            'iss' => $serviceAccountEmail,
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => $this->dateTime->gmtTimestamp(),
            'origins' => [],
            'payload' => ['loyaltyObjects' => [$loyaltyObject]],
        ]));
        $signingInput = $header . '.' . $payload;

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign Google Wallet JWT.');
        }

        return self::SAVE_URL_BASE . $signingInput . '.' . $this->base64Url->encode((string) $signature);
    }
}
