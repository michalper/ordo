<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Email;

use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\EmailMessageInterfaceFactory;
use Ordo\Automation\Model\Email\PendingUnsubscribeHeaderHolder;
use Ordo\Automation\Plugin\Email\EmailMessageUnsubscribeHeaderPlugin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message as SymfonyMessage;

class EmailMessageUnsubscribeHeaderPluginTest extends TestCase
{
    public function testDoesNothingWhenNoUrlIsQueued(): void
    {
        $holder = new PendingUnsubscribeHeaderHolder();
        $result = $this->createMock(EmailMessage::class);
        $result->expects(self::never())->method('getSymfonyMessage');

        $plugin = new EmailMessageUnsubscribeHeaderPlugin($holder);
        $returned = $plugin->afterCreate($this->createStub(EmailMessageInterfaceFactory::class), $result);

        self::assertSame($result, $returned);
    }

    public function testDoesNothingWhenResultIsNotAnEmailMessage(): void
    {
        $holder = new PendingUnsubscribeHeaderHolder();
        $holder->set('https://example.com/ordo/email/unsubscribe/token/42.email.sig');
        $result = $this->createStub(EmailMessageInterface::class);

        $plugin = new EmailMessageUnsubscribeHeaderPlugin($holder);
        $returned = $plugin->afterCreate($this->createStub(EmailMessageInterfaceFactory::class), $result);

        self::assertSame($result, $returned);
        // The queued url must still be consumed even when it can't be applied here - otherwise
        // it would leak onto whichever unrelated EmailMessage gets built next.
        self::assertNull($holder->consume());
    }

    public function testAddsBothHeadersWhenAUrlIsQueued(): void
    {
        $holder = new PendingUnsubscribeHeaderHolder();
        $holder->set('https://example.com/ordo/email/unsubscribe/token/42.email.sig');

        $symfonyMessage = new SymfonyMessage(new Headers());
        $result = $this->createStub(EmailMessage::class);
        $result->method('getSymfonyMessage')->willReturn($symfonyMessage);

        $plugin = new EmailMessageUnsubscribeHeaderPlugin($holder);
        $returned = $plugin->afterCreate($this->createStub(EmailMessageInterfaceFactory::class), $result);

        self::assertSame($result, $returned);
        self::assertSame(
            '<https://example.com/ordo/email/unsubscribe/token/42.email.sig>',
            $symfonyMessage->getHeaders()->get('List-Unsubscribe')?->getBodyAsString()
        );
        self::assertSame(
            'List-Unsubscribe=One-Click',
            $symfonyMessage->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString()
        );
    }
}
