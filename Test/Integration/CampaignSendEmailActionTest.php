<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\Campaign\Action\SendEmail;
use PHPUnit\Framework\TestCase;

/**
 * SendEmail is the one action type that can't be exercised through the full, real dispatch
 * path in CampaignDispatchScenarioTest without either sending a real email or requiring a
 * real, registered email template — following magento-integration-test-lite's "override just
 * the one risky collaborator" pattern: every real dependency (CustomerRepositoryInterface,
 * StoreManagerInterface, translation state) comes from real DI, only the tail end of
 * TransportBuilder::getTransport() (the actual template render + mail transport) is replaced
 * with a spy, since that's the only part that would otherwise hit real infrastructure.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/CampaignSendEmailActionTest.php
 */
class CampaignSendEmailActionTest extends TestCase
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
    }

    public function testExecuteBuildsATransportAddressedToTheRealCustomerWithTemplateVarsFromContext(): void
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);

        $email = 'ordo-automation-send-email-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Recording');
        $customer->setLastname('Transport');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();

        $recordingBuilder = self::$objectManager->create(RecordingTransportBuilder::class);

        /** @var SendEmail $action */
        $action = self::$objectManager->create(SendEmail::class, [
            'transportBuilder' => $recordingBuilder,
        ]);

        $context = ['customer_id' => $this->customerId, 'coupon_code' => 'RECORD-1234'];
        $action->execute($context, ['template' => 'ordo_campaign_generic', 'message' => 'hello']);

        self::assertSame('ordo_campaign_generic', $recordingBuilder->calls['templateIdentifier'] ?? null);
        self::assertNotEmpty($recordingBuilder->calls['to'] ?? []);
        self::assertSame($email, $recordingBuilder->calls['to'][0][0]);
        self::assertSame('RECORD-1234', $recordingBuilder->calls['templateVars']['coupon_code'] ?? null);
        self::assertTrue($recordingBuilder->recordedTransport->sent ?? false, 'sendMessage() must have been called on the transport');
    }

    public function testExecuteDoesNothingWhenCustomerIdMissingFromContext(): void
    {
        $recordingBuilder = self::$objectManager->create(RecordingTransportBuilder::class);

        /** @var SendEmail $action */
        $action = self::$objectManager->create(SendEmail::class, [
            'transportBuilder' => $recordingBuilder,
        ]);

        $context = [];
        $action->execute($context, ['template' => 'ordo_campaign_generic']);

        self::assertArrayNotHasKey('templateIdentifier', $recordingBuilder->calls);
    }
}

/**
 * Delegates every state-accumulating call to the real TransportBuilder (safe — they just set
 * private properties, no I/O), but overrides getTransport() so the actual template render and
 * mail send never happen. Test-only helper, not autoloaded outside Test/Integration.
 */
class RecordingTransportBuilder extends TransportBuilder
{
    /** @var array<string, mixed> */
    public array $calls = [];

    public ?SpyTransport $recordedTransport = null;

    public function setTemplateIdentifier($templateIdentifier)
    {
        $this->calls['templateIdentifier'] = $templateIdentifier;
        return parent::setTemplateIdentifier($templateIdentifier);
    }

    public function addTo($address, $name = null)
    {
        $this->calls['to'][] = [$address, $name];
        return parent::addTo($address, $name);
    }

    public function setTemplateVars($templateVars)
    {
        $this->calls['templateVars'] = $templateVars;
        return parent::setTemplateVars($templateVars);
    }

    public function getTransport()
    {
        $this->recordedTransport = new SpyTransport();
        return $this->recordedTransport;
    }
}

class SpyTransport implements TransportInterface
{
    public bool $sent = false;

    public function sendMessage()
    {
        $this->sent = true;
    }

    public function getMessage()
    {
        return null;
    }
}
