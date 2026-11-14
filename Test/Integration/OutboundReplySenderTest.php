<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Conversation\OutboundReplySender;
use Ordo\Automation\Model\ConversationMessage;
use Ordo\Automation\Model\ConversationMessageFactory;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsent as CustomerConsentResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsent\CollectionFactory as CustomerConsentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\CustomerConsentLog as CustomerConsentLogResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsentLog\CollectionFactory as CustomerConsentLogCollectionFactory;
use Ordo\Automation\Test\Integration\Fixture\RecordingTwilioSmsSender;
use Ordo\Automation\Test\Integration\Fixture\RecordingWhatsAppSender;
use PHPUnit\Framework\TestCase;

/**
 * Real DI, real database, no mocks - the same magento-integration-test-lite "override just the
 * one risky collaborator" shape as CampaignSendSmsActionTest, which this follows closely.
 *
 * OutboundReplySender is the service Controller\Adminhtml\ConversationMessage\Reply actually
 * delegates to - that controller itself is thin (param extraction + a redirect/message, same
 * shape as every other controller in this module, see Test\Unit\Controller\Adminhtml\
 * ConversationMessage\ReplyTest) and is covered at the Unit level with a mocked
 * OutboundReplySender. This is where the real wiring is proven instead: a real ConsentManager
 * consent re-check against a real ordo_customer_consent row, real channel-based dispatch to
 * SmsSenderInterface/WhatsAppSender, and a real new outbound ordo_conversation_message row - only
 * the two provider calls at the very tail end (Twilio/Meta) are faked, via
 * RecordingTwilioSmsSender/RecordingWhatsAppSender (the latter overriding WhatsAppSender's own
 * sendFreeform(), the same "extend the real class, override only the network call" shape
 * RecordingTransportBuilder already uses for CampaignSendEmailActionTest). See docs/adr/0059 for
 * why this class, not a browser-level MFTF test, is the chosen system-level proof for this change.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/OutboundReplySenderTest.php
 */
class OutboundReplySenderTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $conversationMessageIdsToClean = [];

    /** @var int[] */
    private array $customerIdsToClean = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        $resource = self::$objectManager->get(ConversationMessageResource::class);
        $factory = self::$objectManager->get(ConversationMessageFactory::class);
        foreach ($this->conversationMessageIdsToClean as $entityId) {
            $message = $factory->create();
            $resource->load($message, $entityId);
            if ($message->getId()) {
                $resource->delete($message);
            }
        }
        $this->conversationMessageIdsToClean = [];

        $consentCollectionFactory = self::$objectManager->get(CustomerConsentCollectionFactory::class);
        $consentResource = self::$objectManager->get(CustomerConsentResource::class);
        $consentLogCollectionFactory = self::$objectManager->get(CustomerConsentLogCollectionFactory::class);
        $consentLogResource = self::$objectManager->get(CustomerConsentLogResource::class);

        foreach ($this->customerIdsToClean as $customerId) {
            $consents = $consentCollectionFactory->create();
            $consents->addCustomerFilter($customerId);
            foreach ($consents as $consent) {
                $consentResource->delete($consent);
            }

            $logs = $consentLogCollectionFactory->create();
            $logs->addCustomerFilter($customerId);
            foreach ($logs as $log) {
                $consentLogResource->delete($log);
            }
        }
        $this->customerIdsToClean = [];
    }

    public function testReplyOverSmsSendsAndPersistsAnOutboundRow(): void
    {
        $customerId = $this->nextTestCustomerId();
        $this->customerIdsToClean[] = $customerId;
        $entityId = $this->createInboundMessage('sms', $customerId, '+15551234567', 'What is my order status?');

        $smsSender = self::$objectManager->create(RecordingTwilioSmsSender::class);
        $reply = $this->makeSender($smsSender)->reply($entityId, 'Your order shipped yesterday!');
        $this->conversationMessageIdsToClean[] = (int) $reply->getId();

        self::assertSame('+15551234567', $smsSender->calls[0]['toPhone'] ?? null);
        self::assertSame('Your order shipped yesterday!', $smsSender->calls[0]['message'] ?? null);

        $reloaded = $this->reloadMessage((int) $reply->getId());
        self::assertSame('sms', $reloaded->getChannel());
        self::assertSame($customerId, $reloaded->getCustomerId());
        self::assertSame('+15551234567', $reloaded->getFromAddress());
        self::assertSame('Your order shipped yesterday!', $reloaded->getBody());
        self::assertSame(ConversationMessage::DIRECTION_OUTBOUND, $reloaded->getDirection());
        self::assertFalse($reloaded->isStopKeyword());
        self::assertSame('SM_recorded_test_sid', $reloaded->getProviderMessageId());
    }

    public function testReplyOverWhatsAppSendsAndPersistsAnOutboundRow(): void
    {
        $customerId = $this->nextTestCustomerId();
        $this->customerIdsToClean[] = $customerId;
        $entityId = $this->createInboundMessage('whatsapp', $customerId, '+15557654321', 'Is this in stock?');

        $whatsAppSender = self::$objectManager->create(RecordingWhatsAppSender::class);
        $reply = $this->makeSender(null, $whatsAppSender)->reply($entityId, 'Yes, back in stock now!');
        $this->conversationMessageIdsToClean[] = (int) $reply->getId();

        self::assertSame('+15557654321', $whatsAppSender->calls[0]['toPhone'] ?? null);
        self::assertSame('Yes, back in stock now!', $whatsAppSender->calls[0]['message'] ?? null);

        $reloaded = $this->reloadMessage((int) $reply->getId());
        self::assertSame('whatsapp', $reloaded->getChannel());
        self::assertSame(ConversationMessage::DIRECTION_OUTBOUND, $reloaded->getDirection());
        self::assertSame('wamid.recorded_test_id', $reloaded->getProviderMessageId());
    }

    public function testReplyIsRefusedWhenConsentWasWithdrawn(): void
    {
        $customerId = $this->nextTestCustomerId();
        $this->customerIdsToClean[] = $customerId;
        self::$objectManager->get(ConsentManager::class)
            ->setConsent($customerId, ConsentChannel::Sms, false, 'test-fixture');

        $entityId = $this->createInboundMessage('sms', $customerId, '+15551112222', 'Hello');

        $smsSender = self::$objectManager->create(RecordingTwilioSmsSender::class);
        $this->expectException(\RuntimeException::class);
        try {
            $this->makeSender($smsSender)->reply($entityId, 'Hi there!');
        } finally {
            self::assertSame([], $smsSender->calls);
        }
    }

    public function testReplyIsRefusedWhenNoCustomerCouldBeResolved(): void
    {
        $entityId = $this->createInboundMessage('sms', null, '+15559998888', 'Unresolvable number');

        $smsSender = self::$objectManager->create(RecordingTwilioSmsSender::class);
        $this->expectException(\RuntimeException::class);
        try {
            $this->makeSender($smsSender)->reply($entityId, 'Hi there!');
        } finally {
            self::assertSame([], $smsSender->calls);
        }
    }

    public function testReplyIsRefusedForAnAlreadyOutboundMessage(): void
    {
        $customerId = $this->nextTestCustomerId();
        $this->customerIdsToClean[] = $customerId;
        $entityId = $this->createInboundMessage('sms', $customerId, '+15551112222', 'Hello');

        // Flip the row to outbound directly, the same state a real admin reply would already
        // have left it in.
        $resource = self::$objectManager->get(ConversationMessageResource::class);
        $factory = self::$objectManager->get(ConversationMessageFactory::class);
        $message = $factory->create();
        $resource->load($message, $entityId);
        $message->setDirection(ConversationMessage::DIRECTION_OUTBOUND);
        $resource->save($message);

        $smsSender = self::$objectManager->create(RecordingTwilioSmsSender::class);
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->makeSender($smsSender)->reply($entityId, 'Hi there!');
        } finally {
            self::assertSame([], $smsSender->calls);
        }
    }

    public function testReplyIsRefusedForAMessageThatDoesNotExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeSender()->reply(PHP_INT_MAX - 1, 'Hi there!');
    }

    private function createInboundMessage(string $channel, ?int $customerId, string $fromAddress, string $body): int
    {
        $factory = self::$objectManager->get(ConversationMessageFactory::class);
        $resource = self::$objectManager->get(ConversationMessageResource::class);

        $message = $factory->create();
        $message->setChannel($channel);
        $message->setCustomerId($customerId);
        $message->setFromAddress($fromAddress);
        $message->setBody($body);
        $message->setIsStopKeyword(false);
        // "direction" is deliberately left unset here - the real db_schema.xml column default
        // ("inbound") is exactly what every production inbound row relies on (see
        // InboundMessageProcessor, which never sets it either), so this proves that default too.
        $resource->save($message);

        $entityId = (int) $message->getId();
        $this->conversationMessageIdsToClean[] = $entityId;

        return $entityId;
    }

    private function makeSender(
        ?RecordingTwilioSmsSender $smsSender = null,
        ?RecordingWhatsAppSender $whatsAppSender = null
    ): OutboundReplySender {
        return self::$objectManager->create(OutboundReplySender::class, [
            'smsSender' => $smsSender ?? self::$objectManager->create(RecordingTwilioSmsSender::class),
            'whatsAppSender' => $whatsAppSender ?? self::$objectManager->create(RecordingWhatsAppSender::class),
        ]);
    }

    private function reloadMessage(int $entityId): ConversationMessage
    {
        $factory = self::$objectManager->get(ConversationMessageFactory::class);
        $resource = self::$objectManager->get(ConversationMessageResource::class);

        /** @var ConversationMessage $message */
        $message = $factory->create();
        $resource->load($message, $entityId);

        return $message;
    }

    /**
     * No FK from ordo_conversation_message/ordo_customer_consent to customer_entity (see
     * db_schema.xml) - a real Magento customer record isn't needed to exercise this path, only a
     * customer_id value unlikely to collide with a real one in a dev database.
     */
    private function nextTestCustomerId(): int
    {
        return random_int(900000000, 999999999);
    }
}
