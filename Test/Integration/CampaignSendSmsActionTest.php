<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\Campaign\Action\SendSms;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;
use PHPUnit\Framework\TestCase;

/**
 * SendSms is the SMS equivalent of CampaignSendEmailActionTest — same "override just the one
 * risky collaborator" pattern from magento-integration-test-lite: every real dependency
 * (CustomerRepositoryInterface, Helper\Config reading real store config) comes from real DI, only
 * SmsSenderInterface (which would otherwise make a real Twilio API call) is substituted with
 * RecordingTwilioSmsSender below.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/CampaignSendSmsActionTest.php
 */
class CampaignSendSmsActionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private ?int $customerId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
        // See CampaignDispatchScenarioTest::setUpBeforeClass() — required for customer cleanup.
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        if ($this->customerId !== null) {
            try {
                self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
                    ->deleteById($this->customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->customerId = null;
        }

        // Restore — sms/enabled has no <config_data> default, so it's unset/false unless a test
        // turns it on; leaving it flipped on would leak into every other test run afterward.
        // Same explicit store-scope-code reasoning as the test's own setValue() call.
        $storeId = (int) self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)
            ->getStore()->getId();
        self::$objectManager->get(\Magento\Framework\App\MutableScopeConfig::class)->setValue(
            'ordo_automation/sms/enabled',
            0,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function testExecuteSendsSmsToTheRealCustomersConfiguredPhone(): void
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);

        // See magento-testing:magento-integration-test-lite — MutableScopeConfig is the real,
        // controlled config source this lite integration-test pattern uses instead of mocking
        // ScopeConfigInterface, so Config::isSmsEnabled() (real DI, reads real scope config)
        // sees this the same way it would see an admin actually enabling the feature. The scope
        // CODE is passed explicitly (this class's own real, current store id) rather than left
        // null/default - confirmed via a real CI run that in this class's own 'frontend' area
        // (unlike GenerateAiContentActionTest's 'adminhtml'), Config::isSmsEnabled(null) resolves
        // to the real current store's numeric id at read time, which didn't match an override
        // written under a null/default scope code at write time.
        self::$objectManager->get(\Magento\Framework\App\MutableScopeConfig::class)->setValue(
            'ordo_automation/sms/enabled',
            1,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            (int) $storeManager->getStore()->getId()
        );

        $email = 'ordo-automation-send-sms-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Recording');
        $customer->setLastname('Sms');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();

        // A custom attribute set on the data object BEFORE the customer's first (create) save
        // is a known real Magento quirk: it's silently dropped on create and only actually
        // persists on a subsequent (update) save - confirmed via a real CI run of this exact
        // test. Setting it here, on the already-created customer, and saving again works.
        $saved->setCustomAttribute(AddCustomerSmsPhoneAttribute::ATTRIBUTE_CODE, '+15551234567');
        $customerRepository->save($saved);

        // CustomerRepository caches the entity it just saved in Magento\Customer\Model\
        // CustomerRegistry, keyed by id - a later getById() for the same id in this same process
        // (SendSms::execute()'s own call below) returns that cached instance instead of
        // re-reading the database, which is fine when it's the same object reflecting the save
        // above, but removing it here forces a genuinely fresh DB read regardless - confirmed
        // via a real CI run that this was needed for the custom attribute to actually show up.
        self::$objectManager->get(\Magento\Customer\Model\CustomerRegistry::class)
            ->remove($this->customerId);

        // TEMPORARY diagnostic round 2 - the scope-code fix didn't change the symptom, so this
        // checks the raw ScopeConfigInterface directly (bypassing Helper\Config entirely) with
        // the exact store id used at write time, plus what store id Config's own internal
        // resolution would use for a null $storeId call.
        $rawScopeConfig = self::$objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $diagStoreId = (int) $storeManager->getStore()->getId();
        fwrite(STDERR, sprintf(
            "[DIAG2] storeIdAtWrite=%d storeIdNow=%d rawIsSetFlag(storeId)=%s rawIsSetFlag(null)=%s helperIsSmsEnabled=%s mutableScopeConfigClass=%s scopeConfigClass=%s sameInstance=%s\n",
            $diagStoreId,
            $diagStoreId,
            $rawScopeConfig->isSetFlag('ordo_automation/sms/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $diagStoreId) ? 'true' : 'false',
            $rawScopeConfig->isSetFlag('ordo_automation/sms/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null) ? 'true' : 'false',
            self::$objectManager->get(\Ordo\Automation\Helper\Config::class)->isSmsEnabled() ? 'true' : 'false',
            get_class(self::$objectManager->get(\Magento\Framework\App\MutableScopeConfig::class)),
            get_class($rawScopeConfig),
            spl_object_id($rawScopeConfig) === spl_object_id(self::$objectManager->get(\Magento\Framework\App\MutableScopeConfig::class)) ? 'true' : 'false'
        ));

        $recordingSender = self::$objectManager->create(RecordingTwilioSmsSender::class);

        /** @var SendSms $action */
        $action = self::$objectManager->create(SendSms::class, [
            'smsSender' => $recordingSender,
        ]);

        $context = ['customer_id' => $this->customerId];
        $action->execute($context, ['message' => 'Your order shipped!']);

        self::assertSame('+15551234567', $recordingSender->calls[0]['toPhone'] ?? null);
        self::assertSame('Your order shipped!', $recordingSender->calls[0]['message'] ?? null);
    }

    public function testExecuteDoesNothingWhenCustomerIdMissingFromContext(): void
    {
        $recordingSender = self::$objectManager->create(RecordingTwilioSmsSender::class);

        /** @var SendSms $action */
        $action = self::$objectManager->create(SendSms::class, [
            'smsSender' => $recordingSender,
        ]);

        $context = [];
        $action->execute($context, ['message' => 'Your order shipped!']);

        self::assertSame([], $recordingSender->calls);
    }
}

/**
 * Records (toPhone, message) instead of calling the real Twilio API — test-only helper, not
 * autoloaded outside Test/Integration, same role RecordingTransportBuilder plays in
 * CampaignSendEmailActionTest.
 */
class RecordingTwilioSmsSender implements SmsSenderInterface
{
    /** @var array<int, array{toPhone: string, message: string}> */
    public array $calls = [];

    public function send(string $toPhone, string $message): string
    {
        $this->calls[] = ['toPhone' => $toPhone, 'message' => $message];

        return 'SM_recorded_test_sid';
    }
}
