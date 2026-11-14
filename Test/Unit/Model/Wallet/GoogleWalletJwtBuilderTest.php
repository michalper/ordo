<?php
declare(strict_types=1);

// Namespaced function override: an unqualified openssl_sign() call inside
// Ordo\Automation\Model\Wallet resolves to this namespace's function first (PHP's own
// namespace-fallback rule), letting GoogleWalletJwtBuilder's signing-failure branch be exercised
// without adding a test-only seam to the production class - same technique
// Test/Unit/Model/Push/WebPushCryptoTest.php already established for this module's other
// hand-rolled-crypto classes. The "private key can't be loaded" branch below doesn't need an
// override at all - a real invalid PEM string already makes the real openssl_pkey_get_private()
// fail. Defaults to delegating to the real function, so the "real signature actually verifies"
// test below is unaffected unless a test explicitly flips the flag.
namespace Ordo\Automation\Model\Wallet;

$GLOBALS['ordo_test_force_wallet_sign_failure'] = false;

function openssl_sign(string $data, &$signature, $privateKey, int|string $algo = OPENSSL_ALGO_SHA1): bool
{
    if ($GLOBALS['ordo_test_force_wallet_sign_failure'] ?? false) {
        return false;
    }
    return \openssl_sign($data, $signature, $privateKey, $algo);
}

namespace Ordo\Automation\Test\Unit\Model\Wallet;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\Push\BaseSixtyFourUrl;
use Ordo\Automation\Model\Wallet\GoogleWalletJwtBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GoogleWalletJwtBuilderTest extends TestCase
{
    private GoogleWalletJwtBuilder $builder;
    private BaseSixtyFourUrl $base64Url;
    private string $privateKeyPem = '';
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $this->base64Url = new BaseSixtyFourUrl();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKeyPem);
        $this->publicKeyPem = openssl_pkey_get_details($key)['key'];

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_700_000_000);
        $this->builder = new GoogleWalletJwtBuilder($dateTime, $this->base64Url);
    }

    protected function tearDown(): void
    {
        $GLOBALS['ordo_test_force_wallet_sign_failure'] = false;
    }

    public function testBuildsASaveUrlWithARealVerifiableRs256Signature(): void
    {
        $loyaltyObject = ['id' => 'issuer123.42', 'classId' => 'issuer123.rewards'];

        $saveUrl = $this->builder->buildSaveUrl($loyaltyObject, 'svc@example-project.iam.gserviceaccount.com', $this->privateKeyPem);

        self::assertStringStartsWith('https://pay.google.com/gp/v/save/', $saveUrl);
        $jwt = substr($saveUrl, strlen('https://pay.google.com/gp/v/save/'));
        [$header, $payload, $signature] = explode('.', $jwt);

        $headerClaims = json_decode($this->base64Url->decode($header), true);
        self::assertSame('RS256', $headerClaims['alg']);
        self::assertSame('JWT', $headerClaims['typ']);

        $payloadClaims = json_decode($this->base64Url->decode($payload), true);
        self::assertSame('svc@example-project.iam.gserviceaccount.com', $payloadClaims['iss']);
        self::assertSame('google', $payloadClaims['aud']);
        self::assertSame('savetowallet', $payloadClaims['typ']);
        self::assertSame(1_700_000_000, $payloadClaims['iat']);
        self::assertSame([], $payloadClaims['origins']);
        self::assertSame([$loyaltyObject], $payloadClaims['payload']['loyaltyObjects']);

        // The signature must actually verify against the matching public key - proves the
        // signing input/output are mutually consistent with real RSA verification, not just that
        // some bytes came back.
        $signingInput = $header . '.' . $payload;
        $publicKey = openssl_pkey_get_public($this->publicKeyPem);
        self::assertSame(
            1,
            openssl_verify($signingInput, $this->base64Url->decode($signature), $publicKey, OPENSSL_ALGO_SHA256)
        );
    }

    public function testThrowsWhenThePrivateKeyCannotBeLoaded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to load Google Wallet service account private key.');

        $this->builder->buildSaveUrl(['id' => 'x'], 'svc@example.com', 'not a real pem');
    }

    public function testThrowsWhenSigningFails(): void
    {
        $GLOBALS['ordo_test_force_wallet_sign_failure'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to sign Google Wallet JWT.');

        $this->builder->buildSaveUrl(['id' => 'x'], 'svc@example.com', $this->privateKeyPem);
    }
}
