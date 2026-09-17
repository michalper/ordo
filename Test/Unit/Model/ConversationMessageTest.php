<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ConversationMessage;

class ConversationMessageTest extends AbstractModelTestCase
{
    private function makeModel(): ConversationMessage
    {
        return new ConversationMessage($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testGettersAndSettersRoundTrip(): void
    {
        $message = $this->makeModel();
        $message->setChannel('sms')
            ->setCustomerId(42)
            ->setFromAddress('+15551234567')
            ->setBody('STOP')
            ->setProviderMessageId('SM123')
            ->setIsStopKeyword(true);

        self::assertSame('sms', $message->getChannel());
        self::assertSame(42, $message->getCustomerId());
        self::assertSame('+15551234567', $message->getFromAddress());
        self::assertSame('STOP', $message->getBody());
        self::assertSame('SM123', $message->getProviderMessageId());
        self::assertTrue($message->isStopKeyword());
    }

    public function testCustomerIdAndProviderMessageIdAreNullableByDefault(): void
    {
        $message = $this->makeModel();

        self::assertNull($message->getCustomerId());
        self::assertNull($message->getProviderMessageId());
    }

    public function testSetCustomerIdAndProviderMessageIdAcceptExplicitNullToClear(): void
    {
        $message = $this->makeModel();
        $message->setCustomerId(42)->setProviderMessageId('SM123');

        $message->setCustomerId(null)->setProviderMessageId(null);

        self::assertNull($message->getCustomerId());
        self::assertNull($message->getProviderMessageId());
    }

    public function testIsStopKeywordDefaultsToFalse(): void
    {
        $message = $this->makeModel();

        self::assertFalse($message->isStopKeyword());
    }
}
