<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Model\Push\BaseSixtyFourUrl;
use Ordo\Automation\Model\Wallet\GoogleWalletPassLinkBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Real DI, real DB, no mocks (see magento-integration-test-lite / this suite's other tests) -
 * proves the REST/service-layer plumbing docs/adr/0062 describes actually works: a real
 * customer, a real ordo_loyalty_points_ledger balance (earned through the real
 * LoyaltyPointsManager::earnForOrder() path, not inserted directly), a real ordo_customer_score
 * row giving a real Silver tier, and GoogleWalletPassLinkBuilder (auto-wired by the real Magento
 * object graph - proves Helper\Config, LoyaltyPointsManager, LoyaltyTierCalculator,
 * CustomerScoreManager, GoogleWalletLoyaltyObjectBuilder and GoogleWalletJwtBuilder are all wired
 * together correctly, not just individually correct under a Test\Unit mock) signing a real JWT
 * with a locally-generated, self-signed test RSA key configured the exact way a real Google
 * service account key would be. The JWT is independently decoded and signature-verified here,
 * and its embedded LoyaltyObject payload is asserted against the customer's real DB rows.
 *
 * Deliberately scoped to what this stops short of, per docs/adr/0062's own Consequences:
 * - The thin customer-id-resolution wrapper around this (Model\LoyaltyWalletPassManagement,
 *   which the real webapi.xml route under test dispatches to) is covered by its own
 *   Test\Unit\Model\LoyaltyWalletPassManagementTest - UserContextInterface is a real token-auth
 *   construct this plain-bootstrap suite has no way to supply, the same reason no other test in
 *   this directory exercises it either. The REST route itself (DI preference + webapi.xml ACL)
 *   is instead covered by Test\Api\LoyaltyWalletPassApiTest.php, a real-HTTP, real-customer-token
 *   test environment-gated the same way its sibling LoyaltyPointsApiTest already is.
 * - No real Google-issued service account key, no real Issuer ID, and no real device ever
 *   receives this JWT - that gap is permanent and accepted, not pending (docs/adr/0062).
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/LoyaltyWalletPassEndpointTest.php
 */
class LoyaltyWalletPassEndpointTest extends TestCase
{
    private const string SERVICE_ACCOUNT_EMAIL = 'ordo-test@ordo-test-project.iam.gserviceaccount.com';
    private const string ISSUER_ID = '3388000000022199568';
    private const string CLASS_ID = 'ordo_rewards_test_class';

    private static ObjectManagerInterface $objectManager;
    private static string $privateKeyPem;
    private static string $publicKeyPem;
    private static int $storeId;

    private ?int $customerId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$privateKeyPem);
        self::$publicKeyPem = openssl_pkey_get_details($key)['key'];

        self::$storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        self::writeConfig('ordo_automation/loyalty_wallet_pass/enabled', 1);
        self::writeConfig('ordo_automation/loyalty_wallet_pass/google_issuer_id', self::ISSUER_ID);
        self::writeConfig('ordo_automation/loyalty_wallet_pass/google_class_id', self::CLASS_ID);
        self::writeConfig('ordo_automation/loyalty_wallet_pass/google_service_account_email', self::SERVICE_ACCOUNT_EMAIL);
        // The obscure/encrypted field's backend model encrypts on save through the admin config
        // form; writing via ConfigWriter directly stores the raw value unencrypted, so it must be
        // pre-encrypted here the same way Helper\Config::decryptedConfig() expects to reverse.
        self::writeConfig(
            'ordo_automation/loyalty_wallet_pass/google_private_key',
            self::$objectManager->get(\Magento\Framework\Encryption\EncryptorInterface::class)->encrypt(self::$privateKeyPem)
        );
        self::writeConfig('ordo_automation/loyalty_points/enabled', 1);
        self::writeConfig('ordo_automation/loyalty_points/points_per_dollar', 1);

        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([
            'ordo_automation/loyalty_wallet_pass/enabled',
            'ordo_automation/loyalty_wallet_pass/google_issuer_id',
            'ordo_automation/loyalty_wallet_pass/google_class_id',
            'ordo_automation/loyalty_wallet_pass/google_service_account_email',
            'ordo_automation/loyalty_wallet_pass/google_private_key',
            'ordo_automation/loyalty_points/enabled',
            'ordo_automation/loyalty_points/points_per_dollar',
        ] as $path) {
            self::$objectManager->get(ConfigWriter::class)->delete($path, ScopeInterface::SCOPE_STORES, self::$storeId);
        }
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    private static function writeConfig(string $path, int|string $value): void
    {
        self::$objectManager->get(ConfigWriter::class)->save($path, $value, ScopeInterface::SCOPE_STORES, self::$storeId);
    }

    protected function tearDown(): void
    {
        if ($this->customerId !== null) {
            try {
                self::$objectManager->get(CustomerRepositoryInterface::class)->deleteById($this->customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
    }

    public function testBuildsAReallySignedJwtEmbeddingTheCustomersRealBalanceAndTier(): void
    {
        $this->customerId = $this->createCustomer();

        // Real earn path (idempotency-guarded insert into ordo_loyalty_points_ledger), not a
        // direct SQL write - 320 whole dollars at 1 point/$1 = 320 points.
        self::$objectManager->get(LoyaltyPointsManager::class)->earnForOrder($this->customerId, 999001, 320.00);

        // A real ordo_customer_score row crossing the default Silver threshold (100).
        self::$objectManager->get(CustomerScoreManager::class)->addPoints($this->customerId, 150);

        $saveUrl = self::$objectManager->get(GoogleWalletPassLinkBuilder::class)->buildSaveUrl($this->customerId);

        self::assertStringStartsWith('https://pay.google.com/gp/v/save/', $saveUrl);
        $jwt = substr($saveUrl, strlen('https://pay.google.com/gp/v/save/'));
        [$header, $payload, $signature] = explode('.', $jwt);

        $base64Url = self::$objectManager->get(BaseSixtyFourUrl::class);

        $headerClaims = json_decode($base64Url->decode($header), true);
        self::assertSame('RS256', $headerClaims['alg']);

        $payloadClaims = json_decode($base64Url->decode($payload), true);
        self::assertSame(self::SERVICE_ACCOUNT_EMAIL, $payloadClaims['iss']);
        self::assertSame('google', $payloadClaims['aud']);
        self::assertSame('savetowallet', $payloadClaims['typ']);

        $loyaltyObject = $payloadClaims['payload']['loyaltyObjects'][0];
        self::assertSame(self::ISSUER_ID . '.' . $this->customerId, $loyaltyObject['id']);
        self::assertSame(self::ISSUER_ID . '.' . self::CLASS_ID, $loyaltyObject['classId']);
        self::assertSame((string) $this->customerId, $loyaltyObject['accountId']);
        self::assertSame(320, $loyaltyObject['loyaltyPoints']['balance']['int']);
        self::assertSame('Silver', $loyaltyObject['secondaryLoyaltyPoints']['balance']['string']);
        self::assertSame((string) $this->customerId, $loyaltyObject['barcode']['value']);

        // The real signature must verify against the matching public key - proves this is a
        // genuinely well-formed, byte-correct RS256 JWT, not merely "didn't throw".
        $signingInput = $header . '.' . $payload;
        $publicKey = openssl_pkey_get_public(self::$publicKeyPem);
        self::assertSame(
            1,
            openssl_verify($signingInput, $base64Url->decode($signature), $publicKey, OPENSSL_ALGO_SHA256)
        );
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-wallet-pass-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Wallet');
        $customer->setLastname('Pass Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);

        return (int) $saved->getId();
    }
}
