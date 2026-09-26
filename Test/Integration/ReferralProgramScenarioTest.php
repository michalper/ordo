<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Event as MagentoEvent;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Controller\Referral\Track;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Queue\CampaignDispatchGuard;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Model\Referral;
use Ordo\Automation\Model\ReferralManager;
use Ordo\Automation\Observer\DispatchReferralConvertedCampaigns;
use Ordo\Automation\Observer\RedeemReferralCode;
use PHPUnit\Framework\TestCase;

/**
 * Closes the last of the five remaining SCENARIOS.md gaps: the referral program
 * (Model\ReferralManager, Controller\Referral\*, the referral_converted trigger) was unit-tested
 * only, never proven end to end against real DI/DB. Real customers, a real
 * ReferralManager::getOrCreateCode() call, and the two real observers
 * (Observer\RedeemReferralCode, Observer\DispatchReferralConvertedCampaigns) are exercised
 * directly with a real Magento\Framework\Event\Observer wrapping real event data - the same
 * shape Magento's own event dispatcher would build, without needing a full customer_register_
 * success/sales_order_place_after dispatch. Only CampaignDispatchPublisher's own
 * PublisherInterface collaborator is swapped for a recorder (this module's generic
 * publish-to-queue-to-dispatch wiring is already proven once, for a different trigger, by
 * CampaignQueueWiringTest - re-proving that same generic mechanism per trigger would just
 * duplicate coverage that test already owns).
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes the two
 * customers, the order, and the referral/referral-code rows this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/ReferralProgramScenarioTest.php
 */
class ReferralProgramScenarioTest extends TestCase
{
    private const string CONFIG_PATH_ENABLED = 'ordo_automation/referral/enabled';

    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $customerIds = [];

    /** @var int[] */
    private array $orderIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('frontend');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);

        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)->save(self::CONFIG_PATH_ENABLED, 1, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)->delete(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    protected function tearDown(): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();

        $orderTable = self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order');
        foreach ($this->orderIds as $orderId) {
            $connection->delete($orderTable, ['entity_id = ?' => $orderId]);
        }
        $this->orderIds = [];

        $referralTable = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_referral');
        $referralCodeTable = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_referral_code');
        foreach ($this->customerIds as $customerId) {
            $connection->delete($referralTable, ['referrer_customer_id = ?' => $customerId]);
            $connection->delete($referralTable, ['referred_customer_id = ?' => $customerId]);
            $connection->delete($referralCodeTable, ['customer_id = ?' => $customerId]);
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

    public function testFullReferralLifecycleFromCodeToConvertedTrigger(): void
    {
        $referrerCustomerId = $this->createCustomer();
        $referredCustomerId = $this->createCustomer();

        // Step 1: the referrer gets their own real, unique code - what Controller\Referral\
        // MyCode does on request.
        $referralManager = self::$objectManager->get(ReferralManager::class);
        $code = $referralManager->getOrCreateCode($referrerCustomerId);
        self::assertNotSame('', $code);
        self::assertSame($code, $referralManager->getOrCreateCode($referrerCustomerId), 'A second call must return the same code, not mint a new one.');

        // Step 2: the referred customer registers having arrived via that code -
        // Controller\Referral\Track would have stashed it on their session; here it's stashed
        // directly, then the real registration observer is invoked exactly the way Magento's
        // own event dispatcher would call it, real customer_register_success shape.
        $customerSession = self::$objectManager->get(CustomerSession::class);
        $customerSession->setData(Track::SESSION_KEY, $code);

        $referredCustomer = self::$objectManager->get(CustomerRepositoryInterface::class)->getById($referredCustomerId);
        $registrationEvent = new MagentoEvent(['customer' => $referredCustomer]);
        self::$objectManager->get(RedeemReferralCode::class)->execute(new EventObserver(['event' => $registrationEvent]));

        self::assertNull(
            $customerSession->getData(Track::SESSION_KEY),
            'The session key must always be cleared after registration, whether or not a referral was recorded.'
        );
        self::assertSame(
            Referral::STATUS_PENDING,
            $this->loadReferralStatus($referredCustomerId),
            'A real pending ordo_referral row must exist after the registration observer runs.'
        );

        // Step 3: the referred customer's FIRST real order - real sales_order row, then the
        // real order-placed observer is invoked the same way sales_order_place_after would call
        // it. CampaignDispatchPublisher's own PublisherInterface is swapped for a recorder (see
        // this class's own docblock) - everything else is real.
        $orderId = $this->createOrder($referredCustomerId);

        $recordingPublisher = new RecordingPublisherInterface();
        $publisher = self::$objectManager->create(CampaignDispatchPublisher::class, [
            'publisher' => $recordingPublisher,
        ]);
        $observer = self::$objectManager->create(DispatchReferralConvertedCampaigns::class, [
            'campaignDispatchPublisher' => $publisher,
        ]);

        $order = self::$objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($orderId);
        $orderPlacedEvent = new MagentoEvent(['order' => $order]);
        $observer->execute(new EventObserver(['event' => $orderPlacedEvent]));

        self::assertCount(1, $recordingPublisher->published, 'A first order for a referred customer must publish referral_converted.');
        self::assertSame('ordo.automation.campaign.dispatch', $recordingPublisher->published[0]['topic']);

        $serializer = self::$objectManager->get(\Magento\Framework\Serialize\SerializerInterface::class);
        $payload = $serializer->unserialize($recordingPublisher->published[0]['body']);
        self::assertSame('referral_converted', $payload['trigger_event']);
        self::assertSame($referrerCustomerId, $payload['context']['customer_id'], 'The trigger must target the REFERRER, not the customer who just ordered.');
        self::assertSame($referredCustomerId, $payload['context']['referred_customer_id']);
        self::assertSame($orderId, $payload['context']['order_id']);

        self::assertSame(
            Referral::STATUS_CONVERTED,
            $this->loadReferralStatus($referredCustomerId),
            'The referral row itself must now be marked converted.'
        );

        // Step 4: a repeat call (e.g. a second order somehow re-triggering the observer) must
        // never convert - and must never re-publish - the same referral twice.
        self::assertNull($referralManager->markConvertedAndGetReferrer($referredCustomerId));
    }

    private function loadReferralStatus(int $referredCustomerId): ?string
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_referral');

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
        $customer->setEmail('ordo-referral-test-' . uniqid('', true) . '@example.test');
        $customer->setFirstname('Referral');
        $customer->setLastname('Test');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function createOrder(int $customerId): int
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $table = self::$objectManager->get(ResourceConnection::class)->getTableName('sales_order');
        $storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $connection->insert($table, [
            'customer_id' => $customerId,
            'grand_total' => 75.0,
            'state' => 'complete',
            'status' => 'complete',
            'store_id' => $storeId,
            'created_at' => date('Y-m-d H:i:s'),
            'increment_id' => 'ORDOREFTEST-' . uniqid('', true),
        ]);

        $orderId = (int) $connection->lastInsertId($table);
        $this->orderIds[] = $orderId;

        return $orderId;
    }
}

/**
 * Records (topic, body) instead of publishing to a real queue - the one collaborator this test
 * swaps, same "override just the risky collaborator" pattern every other Test/Integration file
 * already uses.
 */
class RecordingPublisherInterface implements PublisherInterface
{
    /** @var array<int, array{topic: string, body: string}> */
    public array $published = [];

    public function publish($topicName, $data)
    {
        $this->published[] = ['topic' => $topicName, 'body' => (string) $data];
    }
}
