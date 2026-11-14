<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendRcs;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;
use Ordo\Automation\Test\Integration\Fixture\RecordingRcsSender;
use PHPUnit\Framework\TestCase;

/**
 * SendRcs is byte-for-byte the same shape as SendSms (both extend AbstractPhoneMessageAction,
 * see SendRcs's own docblock) and had only the Test/Unit treatment every other channel action
 * already outgrew - found via the 2026-11-14 full-sweep audit (Test/Mftf/SCENARIOS.md §42).
 * This gives it the exact extra Test/Integration treatment
 * Test/Integration/CampaignSendSmsActionTest.php already gives send_sms, for consistency with
 * this module's own established per-channel-action precedent - not because any real risk was
 * found (the accepted "no live provider account, unit-only otherwise" posture send_whatsapp/
 * send_push already have applies here too).
 *
 * Same "override just the one risky collaborator" pattern: every real dependency
 * (CustomerRepositoryInterface, Helper\Config reading real store config) comes from real DI, only
 * RcsSenderInterface (which would otherwise make a real Twilio API call) is substituted with
 * RecordingRcsSender.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/CampaignSendRcsActionTest.php
 */
class CampaignSendRcsActionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private ?int $customerId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
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
    }

    /**
     * Same MutableScopeConfig-instance-sharing requirement CampaignSendSmsActionTest's own
     * createConfigWithSmsEnabled() documents for ordo_channels/sms/enabled - scoped to the real
     * customer's own resolved store, not the ambient default.
     */
    private function createConfigWithRcsEnabled(): Config
    {
        $storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_channels/rcs/enabled', 1, ScopeInterface::SCOPE_STORE, $storeId);

        return self::$objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);
    }

    public function testExecuteSendsRcsToTheRealCustomersConfiguredPhone(): void
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);

        $email = 'ordo-automation-send-rcs-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Recording');
        $customer->setLastname('Rcs');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();

        // Same known Magento quirk CampaignSendSmsActionTest documents: a custom attribute set
        // before the FIRST save is silently dropped on create, only persists on a later update.
        $saved->setCustomAttribute(AddCustomerSmsPhoneAttribute::ATTRIBUTE_CODE, '+15559876543');
        $customerRepository->save($saved);

        self::$objectManager->get(\Magento\Customer\Model\CustomerRegistry::class)->remove($this->customerId);

        self::$objectManager->get(ConsentManager::class)
            ->setTopicConsent($this->customerId, ConsentChannel::Rcs, ConsentTopic::Promotional, true, 'test-fixture');

        $recordingSender = self::$objectManager->create(RecordingRcsSender::class);

        /** @var SendRcs $action */
        $action = self::$objectManager->create(SendRcs::class, [
            'rcsSender' => $recordingSender,
            'config' => $this->createConfigWithRcsEnabled(),
        ]);

        $context = ['customer_id' => $this->customerId];
        $action->execute($context, ['message' => 'Your order shipped!']);

        self::assertSame('+15559876543', $recordingSender->calls[0]['toPhone'] ?? null);
        self::assertSame('Your order shipped!', $recordingSender->calls[0]['message'] ?? null);
    }

    public function testExecuteDoesNothingWhenCustomerIdMissingFromContext(): void
    {
        $recordingSender = self::$objectManager->create(RecordingRcsSender::class);

        /** @var SendRcs $action */
        $action = self::$objectManager->create(SendRcs::class, [
            'rcsSender' => $recordingSender,
        ]);

        $context = [];
        $action->execute($context, ['message' => 'Your order shipped!']);

        self::assertSame([], $recordingSender->calls);
    }
}
