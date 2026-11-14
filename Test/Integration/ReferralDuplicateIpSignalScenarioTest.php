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
use Ordo\Automation\Model\Referral;
use Ordo\Automation\Model\ReferralManager;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/database coverage for docs/adr/0056's duplicate_ip_flag signal
 * (Model\ReferralManager::recordSignup()'s private isDuplicateIpSignup()) — the one part of the
 * referral fraud-signal feature ReferralProgramScenarioTest never exercised (it predates this
 * feature and only proves the code/converted lifecycle, never signup_ip/duplicate_ip_flag).
 * `Test/Unit/Model/ReferralManagerTest.php` already proves the branching logic against a mocked
 * collection; this suite proves the real ordo_referral schema (the new signup_ip column, the new
 * (referrer_customer_id, signup_ip) index, Collection::addReferrerCustomerFilter()/
 * addSignupIpFilter()) actually round-trips through a real database the way the unit test
 * assumed.
 *
 * No transactional rollback (see magento-integration-test-lite) — tearDown() deletes every
 * referral row and customer this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/ReferralDuplicateIpSignalScenarioTest.php
 */
class ReferralDuplicateIpSignalScenarioTest extends TestCase
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
        $referralTable = $resourceConnection->getTableName('ordo_referral');

        foreach ($this->customerIds as $customerId) {
            $connection->delete($referralTable, ['referrer_customer_id = ?' => $customerId]);
            $connection->delete($referralTable, ['referred_customer_id = ?' => $customerId]);
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

    /**
     * The core scenario the ADR names: a referrer farming reward by referring a second,
     * disposable account from the same IP. The referrer's FIRST referral has nothing to compare
     * against yet (fails open, no flag) — the SECOND, from the same IP under the SAME referrer,
     * is the real signal and must be flagged.
     */
    public function testSecondSignupFromSameIpUnderSameReferrerIsFlagged(): void
    {
        $referrerId = $this->createCustomer();
        $firstReferredId = $this->createCustomer();
        $secondReferredId = $this->createCustomer();

        $referralManager = self::$objectManager->get(ReferralManager::class);

        self::assertTrue($referralManager->recordSignup($referrerId, $firstReferredId, '203.0.113.10'));
        self::assertFalse(
            $this->loadDuplicateIpFlag($firstReferredId),
            'the referrer\'s very first referral has nothing to compare against yet - must fail open, not flag'
        );

        self::assertTrue($referralManager->recordSignup($referrerId, $secondReferredId, '203.0.113.10'));
        self::assertTrue(
            $this->loadDuplicateIpFlag($secondReferredId),
            'a second referred signup sharing the first one\'s IP, under the same referrer, must be flagged'
        );

        // Still recorded exactly as before - "detect and surface, not detect and block" (ADR 0056).
        self::assertSame(Referral::STATUS_PENDING, $this->loadStatus($secondReferredId));
    }

    public function testDifferentIpUnderSameReferrerIsNotFlagged(): void
    {
        $referrerId = $this->createCustomer();
        $firstReferredId = $this->createCustomer();
        $secondReferredId = $this->createCustomer();

        $referralManager = self::$objectManager->get(ReferralManager::class);

        self::assertTrue($referralManager->recordSignup($referrerId, $firstReferredId, '198.51.100.1'));
        self::assertTrue($referralManager->recordSignup($referrerId, $secondReferredId, '198.51.100.2'));

        self::assertFalse(
            $this->loadDuplicateIpFlag($secondReferredId),
            'a genuinely different IP under the same referrer must not be flagged'
        );
    }

    /**
     * The same IP shared under two DIFFERENT referrers must never be flagged -
     * isDuplicateIpSignup() compares against this referrer's OWN other referred signups only,
     * never across referrers (same office/NAT referring through two different friends is not the
     * farming shape this guards against).
     */
    public function testSameIpUnderDifferentReferrersIsNotFlagged(): void
    {
        $referrerOneId = $this->createCustomer();
        $referrerTwoId = $this->createCustomer();
        $referredUnderOneId = $this->createCustomer();
        $referredUnderTwoId = $this->createCustomer();

        $referralManager = self::$objectManager->get(ReferralManager::class);

        self::assertTrue($referralManager->recordSignup($referrerOneId, $referredUnderOneId, '192.0.2.50'));
        self::assertTrue($referralManager->recordSignup($referrerTwoId, $referredUnderTwoId, '192.0.2.50'));

        self::assertFalse(
            $this->loadDuplicateIpFlag($referredUnderTwoId),
            'the same IP under a DIFFERENT referrer must not be flagged - only a referrer\'s own repeat signups count'
        );
    }

    /**
     * No signup IP captured at all (RemoteAddress returned nothing) must fail open - no signal
     * captured is not the same thing as a flag raised (ADR 0056).
     */
    public function testMissingSignupIpNeverFlags(): void
    {
        $referrerId = $this->createCustomer();
        $firstReferredId = $this->createCustomer();
        $secondReferredId = $this->createCustomer();

        $referralManager = self::$objectManager->get(ReferralManager::class);

        self::assertTrue($referralManager->recordSignup($referrerId, $firstReferredId, null));
        self::assertTrue($referralManager->recordSignup($referrerId, $secondReferredId, null));

        self::assertFalse($this->loadDuplicateIpFlag($firstReferredId));
        self::assertFalse($this->loadDuplicateIpFlag($secondReferredId));
        self::assertNull($this->loadSignupIp($secondReferredId));
    }

    private function loadDuplicateIpFlag(int $referredCustomerId): bool
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_referral');

        $value = $connection->fetchOne(
            $connection->select()->from($table, 'duplicate_ip_flag')->where('referred_customer_id = ?', $referredCustomerId)
        );

        return (bool) $value;
    }

    private function loadSignupIp(int $referredCustomerId): ?string
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_referral');

        $value = $connection->fetchOne(
            $connection->select()->from($table, 'signup_ip')->where('referred_customer_id = ?', $referredCustomerId)
        );

        return $value !== false ? $value : null;
    }

    private function loadStatus(int $referredCustomerId): ?string
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_referral');

        $value = $connection->fetchOne(
            $connection->select()->from($table, 'status')->where('referred_customer_id = ?', $referredCustomerId)
        );

        return $value !== false ? (string) $value : null;
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $customer = $customerFactory->create();
        $customer->setEmail('ordo-referral-ip-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Referral');
        $customer->setLastname('IpTest');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }
}
