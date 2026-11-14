<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Email\UnsubscribeToken;
use Ordo\Automation\Model\Email\UnsubscribeTokenManager;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/database coverage for docs/adr/0058's no-login one-click unsubscribe - the one part of
 * the feature that was Test/Unit-only (every existing test mocks EncryptorInterface/
 * ConsentManager; see Test/Unit/Model/Email/UnsubscribeTokenManagerTest.php and
 * Test/Unit/Controller/Email/UnsubscribeTest.php). This suite proves what a mock can't: that
 * UnsubscribeTokenManager::generate()/resolve() actually round-trips through the installation's
 * REAL crypt key (Magento\Framework\Encryption\EncryptorInterface, read from the real
 * app/etc/env.php, not a stubbed hash), and that acting on a resolved token the exact way
 * Controller\Email\Unsubscribe::execute() does - Model\ConsentManager::setConsent($customerId,
 * $channel, false, 'unsubscribe_link') - writes a real, durable opt-out any later send_email/
 * send_sms dispatch will actually see.
 *
 * The HTTP controller itself (request parsing, the rendered confirmation page, the GET/POST
 * CsrfAwareActionInterface opt-out) stays correctly Test/Unit-only - there is no new admin UI
 * and no browser-observable effect beyond a storefront page MFTF has no particular reason to
 * drive just to see a flash message (same reasoning this module already applies to Controller\
 * Webhook\Receive and Controller\Sms\Reply, see Test/Mftf/SCENARIOS.md §22/§23) - what genuinely
 * needed real DI/DB proof was the signed-token round trip and the consent write it drives, which
 * is exactly this controller's entire real logic once the HTTP plumbing is peeled away.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes every
 * customer/consent row this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/EmailUnsubscribeTokenScenarioTest.php
 */
class EmailUnsubscribeTokenScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $customerIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('frontend');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        foreach ($this->customerIds as $customerId) {
            $connection->delete($resourceConnection->getTableName('ordo_customer_consent'), ['customer_id = ?' => $customerId]);
            $connection->delete($resourceConnection->getTableName('ordo_customer_consent_log'), ['customer_id = ?' => $customerId]);
        }

        foreach ($this->customerIds as $customerId) {
            try {
                self::$objectManager->get(CustomerRepositoryInterface::class)->deleteById($customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
        $this->customerIds = [];
    }

    public function testAGenuineTokenResolvesAndUnsubscribingWritesARealConsentRow(): void
    {
        $customerId = $this->createCustomer();

        $tokenManager = self::$objectManager->get(UnsubscribeTokenManager::class);
        $consentManager = self::$objectManager->get(ConsentManager::class);

        self::assertTrue($consentManager->hasConsent($customerId, ConsentChannel::Email), 'a fresh customer has no row yet - opt-out register default is consented');

        $token = $tokenManager->generate($customerId, ConsentChannel::Email);
        self::assertNotSame('', $token);

        $payload = $tokenManager->resolve($token);
        self::assertInstanceOf(UnsubscribeToken::class, $payload);
        self::assertSame($customerId, $payload->customerId);
        self::assertSame(ConsentChannel::Email, $payload->channel);

        // This is exactly what Controller\Email\Unsubscribe::execute() does with a resolved
        // payload.
        $consentManager->setConsent($payload->customerId, $payload->channel, false, 'unsubscribe_link');

        self::assertFalse(
            $consentManager->hasConsent($customerId, ConsentChannel::Email),
            'a real ordo_customer_consent row must now exist and record the opt-out'
        );

        $logRow = $this->loadLatestConsentLogRow($customerId);
        self::assertNotNull($logRow, 'setConsent() must append a real ordo_customer_consent_log row too');
        self::assertSame('email', $logRow['channel']);
        self::assertSame('unsubscribe_link', $logRow['source']);
        self::assertSame(0, (int) $logRow['consented']);
    }

    /**
     * The token is per (customer, channel) - resolving it for one channel must not also
     * unsubscribe a different one (ADR 0058 scopes this to send_email/the email channel
     * specifically).
     */
    public function testATokenIssuedForOneChannelDoesNotResolveForAnother(): void
    {
        $customerId = $this->createCustomer();

        $tokenManager = self::$objectManager->get(UnsubscribeTokenManager::class);
        $emailToken = $tokenManager->generate($customerId, ConsentChannel::Email);

        // Swap the channel segment of a genuine token for a different one without re-signing -
        // the signature was computed over the ORIGINAL channel, so this must fail to resolve,
        // not silently resolve as the tampered channel.
        $parts = explode('.', $emailToken);
        $tamperedToken = $parts[0] . '.sms.' . $parts[2];

        self::assertNull(
            $tokenManager->resolve($tamperedToken),
            'swapping the channel without re-signing must invalidate the token, not silently repoint it'
        );
    }

    /**
     * A forged/corrupted signature must not resolve - real HMAC verification via the real crypt
     * key, not a mocked hash_equals() that could let anything through.
     */
    public function testATamperedSignatureDoesNotResolve(): void
    {
        $customerId = $this->createCustomer();

        $tokenManager = self::$objectManager->get(UnsubscribeTokenManager::class);
        $genuineToken = $tokenManager->generate($customerId, ConsentChannel::Email);

        $forgedToken = $customerId . '.email.not-the-real-signature';
        self::assertNotSame($genuineToken, $forgedToken);
        self::assertNull($tokenManager->resolve($forgedToken));

        // The genuine token for the same customer/channel must still resolve correctly -
        // confirms the rejection above is about the forged signature specifically, not a
        // misconfigured encryptor rejecting everything.
        $payload = $tokenManager->resolve($genuineToken);
        self::assertInstanceOf(UnsubscribeToken::class, $payload);
        self::assertSame($customerId, $payload->customerId);
    }

    private function loadLatestConsentLogRow(int $customerId): ?array
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_customer_consent_log');

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('customer_id = ?', $customerId)->order('entity_id DESC')->limit(1)
        );

        return $row !== false ? $row : null;
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-unsubscribe-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Unsubscribe');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());

        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }
}
