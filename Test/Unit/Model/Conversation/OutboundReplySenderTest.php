<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Conversation;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Conversation\OutboundReplySender;
use Ordo\Automation\Model\ConversationMessage;
use Ordo\Automation\Model\ConversationMessageFactory;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OutboundReplySender is the one write path that ever sends FROM the admin back to a customer -
 * every branch (empty body, missing/non-inbound row, unresolvable customer, withdrawn consent,
 * unsupported channel, both real sms/whatsapp sends) is covered here with every collaborator
 * mocked; Test\Integration\OutboundReplySenderTest proves the real wiring behind
 * SmsSenderInterface/WhatsAppSender/ConsentManager actually works end to end.
 */
class OutboundReplySenderTest extends TestCase
{
    private ConversationMessageFactory&\PHPUnit\Framework\MockObject\MockObject $conversationMessageFactory;
    private ConversationMessageResource&\PHPUnit\Framework\MockObject\MockObject $conversationMessageResource;
    private ConsentManager&\PHPUnit\Framework\MockObject\MockObject $consentManager;
    private SmsSenderInterface&\PHPUnit\Framework\MockObject\MockObject $smsSender;
    private WhatsAppSender&\PHPUnit\Framework\MockObject\MockObject $whatsAppSender;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->conversationMessageFactory = $this->createMock(ConversationMessageFactory::class);
        $this->conversationMessageResource = $this->createMock(ConversationMessageResource::class);
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->smsSender = $this->createMock(SmsSenderInterface::class);
        $this->whatsAppSender = $this->createMock(WhatsAppSender::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeSender(): OutboundReplySender
    {
        return new OutboundReplySender(
            $this->conversationMessageFactory,
            $this->conversationMessageResource,
            $this->consentManager,
            $this->smsSender,
            $this->whatsAppSender,
            $this->logger
        );
    }

    private function makeMessage(array $data = []): ConversationMessage
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $message = new ConversationMessage(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $resource
        );
        $message->setData($data);

        return $message;
    }

    /**
     * Every create() call gets a fresh, blank model - load() (stubbed separately per test)
     * populates whichever one is passed to it as the "original" row; a later create() call
     * (building the new outbound row) is simply never load()-ed, exactly like
     * Test\Unit\Model\Conversation\InboundMessageProcessorTest's own stubConversationMessage().
     */
    private function stubFreshMessagesFromFactory(): void
    {
        $this->conversationMessageFactory->method('create')->willReturnCallback(
            fn () => $this->makeMessage()
        );
    }

    private function stubOriginalMessage(array $data): void
    {
        $this->conversationMessageResource->method('load')->willReturnCallback(
            function ($model) use ($data) {
                $model->setData($data);
                return $this->conversationMessageResource;
            }
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyRejectsAnEmptyBodyWithoutTouchingAnything(): void
    {
        $this->conversationMessageResource->expects(self::never())->method('load');

        $this->expectException(\InvalidArgumentException::class);
        $this->makeSender()->reply(5, '   ');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyRejectsAMissingMessage(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->makeSender()->reply(999, 'Hi there!');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyRejectsAnAlreadyOutboundMessage(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 5,
            'channel' => 'sms',
            'customer_id' => 42,
            'from_address' => '+15551234567',
            'direction' => ConversationMessage::DIRECTION_OUTBOUND,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->makeSender()->reply(5, 'Hi there!');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyRefusesAndLogsWhenNoCustomerCouldBeResolved(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 5,
            'channel' => 'sms',
            'customer_id' => null,
            'from_address' => '+15559998888',
            'direction' => ConversationMessage::DIRECTION_INBOUND,
        ]);

        $this->logger->expects(self::once())->method('error');
        $this->consentManager->expects(self::never())->method('hasConsent');
        $this->smsSender->expects(self::never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->makeSender()->reply(5, 'Hi there!');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyRefusesAnUnrecognizedChannelWithoutCheckingConsent(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 5,
            'channel' => 'carrier_pigeon',
            'customer_id' => 42,
            'from_address' => '+15551234567',
            'direction' => ConversationMessage::DIRECTION_INBOUND,
        ]);

        $this->consentManager->expects(self::never())->method('hasConsent');

        $this->expectException(\RuntimeException::class);
        $this->makeSender()->reply(5, 'Hi there!');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyRefusesWhenConsentWasWithdrawn(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 5,
            'channel' => 'sms',
            'customer_id' => 42,
            'from_address' => '+15551234567',
            'direction' => ConversationMessage::DIRECTION_INBOUND,
        ]);
        $this->consentManager->method('hasConsent')->with(42, ConsentChannel::Sms)->willReturn(false);
        $this->smsSender->expects(self::never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->makeSender()->reply(5, 'Hi there!');
    }

    /**
     * A row whose channel resolves to a real ConsentChannel case other than Sms/WhatsApp can
     * only happen defensively (ordo_conversation_message.channel is only ever "sms"/"whatsapp" in
     * production) - still covered, since this is the match()'s own default arm, fail-closed
     * rather than silently no-op.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testReplyRefusesASupportedConsentChannelThatIsNotASendableChannel(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 5,
            'channel' => 'email',
            'customer_id' => 42,
            'from_address' => 'customer@example.test',
            'direction' => ConversationMessage::DIRECTION_INBOUND,
        ]);
        $this->consentManager->method('hasConsent')->with(42, ConsentChannel::Email)->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->makeSender()->reply(5, 'Hi there!');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyOverSmsSendsAndPersistsAnOutboundRow(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 5,
            'channel' => 'sms',
            'customer_id' => 42,
            'from_address' => '+15551234567',
            'direction' => ConversationMessage::DIRECTION_INBOUND,
        ]);
        $this->consentManager->method('hasConsent')->with(42, ConsentChannel::Sms)->willReturn(true);
        $this->smsSender->expects(self::once())->method('send')
            ->with('+15551234567', 'Your order shipped!')
            ->willReturn('SM123');
        $this->whatsAppSender->expects(self::never())->method('sendFreeform');

        $saved = null;
        $this->conversationMessageResource->expects(self::once())->method('save')
            ->willReturnCallback(function (ConversationMessage $message) use (&$saved) {
                $saved = $message;
                return $this->conversationMessageResource;
            });
        $this->logger->expects(self::once())->method('info');

        $reply = $this->makeSender()->reply(5, 'Your order shipped!');

        self::assertSame($saved, $reply);
        self::assertSame('sms', $reply->getChannel());
        self::assertSame(42, $reply->getCustomerId());
        self::assertSame('+15551234567', $reply->getFromAddress());
        self::assertSame('Your order shipped!', $reply->getBody());
        self::assertSame('SM123', $reply->getProviderMessageId());
        self::assertFalse($reply->isStopKeyword());
        self::assertSame(ConversationMessage::DIRECTION_OUTBOUND, $reply->getDirection());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyOverWhatsAppSendsAFreeformMessage(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 7,
            'channel' => 'whatsapp',
            'customer_id' => 42,
            'from_address' => '+15557654321',
            'direction' => ConversationMessage::DIRECTION_INBOUND,
        ]);
        $this->consentManager->method('hasConsent')->with(42, ConsentChannel::WhatsApp)->willReturn(true);
        $this->whatsAppSender->expects(self::once())->method('sendFreeform')
            ->with('+15557654321', 'Yes, in stock!')
            ->willReturn('wamid.123');
        $this->smsSender->expects(self::never())->method('send');
        $this->conversationMessageResource->expects(self::once())->method('save');

        $reply = $this->makeSender()->reply(7, 'Yes, in stock!');

        self::assertSame('whatsapp', $reply->getChannel());
        self::assertSame('wamid.123', $reply->getProviderMessageId());
        self::assertSame(ConversationMessage::DIRECTION_OUTBOUND, $reply->getDirection());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReplyTrimsTheBodyBeforeSending(): void
    {
        $this->stubFreshMessagesFromFactory();
        $this->stubOriginalMessage([
            'entity_id' => 5,
            'channel' => 'sms',
            'customer_id' => 42,
            'from_address' => '+15551234567',
            'direction' => ConversationMessage::DIRECTION_INBOUND,
        ]);
        $this->consentManager->method('hasConsent')->willReturn(true);
        $this->smsSender->expects(self::once())->method('send')
            ->with('+15551234567', 'trimmed')
            ->willReturn('SM123');

        $this->makeSender()->reply(5, '  trimmed  ');
    }
}
