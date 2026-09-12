<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Conversation;

use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Conversation\InboundMessageProcessor;
use Ordo\Automation\Model\Conversation\StopKeywordDetector;
use Ordo\Automation\Model\ConversationMessage;
use Ordo\Automation\Model\ConversationMessageFactory;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\Collection as MessageLogCollection;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The compliance-critical path of the whole feature: a STOP-keyword reply MUST revoke consent
 * through the exact same Model\ConsentManager every other channel checks before sending, and that
 * revocation must be unconditional - not gated by any config flag, since the roadmap item this
 * class implements explicitly calls it "mandatory". Every test here uses a REAL StopKeywordDetector
 * (not a mock) so these prove the actual keyword matching the processor relies on, not a stubbed
 * stand-in.
 */
class InboundMessageProcessorTest extends TestCase
{
    private ConversationMessageFactory&\PHPUnit\Framework\MockObject\MockObject $conversationMessageFactory;
    private ConversationMessageResource&\PHPUnit\Framework\MockObject\MockObject $conversationMessageResource;
    private MessageLogCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $messageLogCollectionFactory;
    private ConsentManager&\PHPUnit\Framework\MockObject\MockObject $consentManager;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->conversationMessageFactory = $this->createMock(ConversationMessageFactory::class);
        $this->conversationMessageResource = $this->createMock(ConversationMessageResource::class);
        $this->messageLogCollectionFactory = $this->createMock(MessageLogCollectionFactory::class);
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeProcessor(): InboundMessageProcessor
    {
        return new InboundMessageProcessor(
            $this->conversationMessageFactory,
            $this->conversationMessageResource,
            $this->messageLogCollectionFactory,
            $this->consentManager,
            new StopKeywordDetector(),
            $this->logger
        );
    }

    private function stubResolvedCustomer(?int $customerId): void
    {
        $log = $this->createStub(MessageLog::class);
        $log->method('getId')->willReturn($customerId !== null ? 999 : null);
        $log->method('getCustomerId')->willReturn($customerId);

        $collection = $this->createStub(MessageLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($log);

        $this->messageLogCollectionFactory->method('create')->willReturn($collection);
    }

    private function stubConversationMessage(): ConversationMessage&\PHPUnit\Framework\MockObject\MockObject
    {
        $message = $this->createMock(ConversationMessage::class);
        $message->method('setChannel')->willReturnSelf();
        $message->method('setCustomerId')->willReturnSelf();
        $message->method('setFromAddress')->willReturnSelf();
        $message->method('setBody')->willReturnSelf();
        $message->method('setProviderMessageId')->willReturnSelf();
        $message->method('setIsStopKeyword')->willReturnSelf();
        $this->conversationMessageFactory->method('create')->willReturn($message);

        return $message;
    }

    /**
     * @return array<string, array{string, ConsentChannel}>
     */
    public static function stopKeywordAndChannelProvider(): array
    {
        return [
            'STOP over SMS' => ['STOP', ConsentChannel::Sms],
            'stop lowercase over SMS' => ['stop', ConsentChannel::Sms],
            'UNSUBSCRIBE over WhatsApp' => ['UNSUBSCRIBE', ConsentChannel::WhatsApp],
            'Cancel mixed case over WhatsApp' => ['Cancel', ConsentChannel::WhatsApp],
            'END over SMS' => ['END', ConsentChannel::Sms],
            'quit over WhatsApp' => ['quit', ConsentChannel::WhatsApp],
        ];
    }

    #[DataProvider('stopKeywordAndChannelProvider')]
    #[AllowMockObjectsWithoutExpectations]
    public function testStopKeywordRevokesConsentThroughConsentManagerForTheResolvedCustomerAndChannel(
        string $body,
        ConsentChannel $channel
    ): void {
        $this->stubResolvedCustomer(42);
        $message = $this->stubConversationMessage();
        $message->expects(self::once())->method('setIsStopKeyword')->with(true)->willReturnSelf();
        $this->conversationMessageResource->expects(self::once())->method('save')->with($message);

        $this->consentManager->expects(self::once())->method('setConsent')
            ->with(42, $channel, false, 'stop_keyword');

        $this->makeProcessor()->process($channel, '+15551234567', $body, 'MSG123');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNonStopKeywordMessageDoesNotTouchConsentManager(): void
    {
        $this->stubResolvedCustomer(42);
        $this->stubConversationMessage();

        $this->consentManager->expects(self::never())->method('setConsent');

        $this->makeProcessor()->process(ConsentChannel::Sms, '+15551234567', 'Thanks for the update!', 'MSG124');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testStopKeywordFromAnUnresolvableCustomerDoesNotCallConsentManagerButLogsAnError(): void
    {
        $this->stubResolvedCustomer(null);
        $message = $this->stubConversationMessage();
        $message->expects(self::once())->method('setIsStopKeyword')->with(true)->willReturnSelf();
        $this->conversationMessageResource->expects(self::once())->method('save')->with($message);

        $this->consentManager->expects(self::never())->method('setConsent');
        $this->logger->expects(self::once())->method('error');

        $this->makeProcessor()->process(ConsentChannel::Sms, '+15559999999', 'STOP', null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testStopKeywordLogsAnInfoAuditMessageOnSuccessfulRevocation(): void
    {
        $this->stubResolvedCustomer(7);
        $this->stubConversationMessage();

        $this->logger->expects(self::once())->method('info');

        $this->makeProcessor()->process(ConsentChannel::WhatsApp, '+15551112222', 'STOP', 'wamid.123');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConversationMessageIsAlwaysStoredRegardlessOfStopKeyword(): void
    {
        $this->stubResolvedCustomer(3);
        $message = $this->stubConversationMessage();
        $message->expects(self::once())->method('setChannel')->with('sms')->willReturnSelf();
        $message->expects(self::once())->method('setCustomerId')->with(3)->willReturnSelf();
        $message->expects(self::once())->method('setFromAddress')->with('+15551234567')->willReturnSelf();
        $message->expects(self::once())->method('setBody')->with('hello there')->willReturnSelf();
        $message->expects(self::once())->method('setProviderMessageId')->with('SM1')->willReturnSelf();
        $this->conversationMessageResource->expects(self::once())->method('save')->with($message);

        $this->makeProcessor()->process(ConsentChannel::Sms, '+15551234567', 'hello there', 'SM1');
    }
}
