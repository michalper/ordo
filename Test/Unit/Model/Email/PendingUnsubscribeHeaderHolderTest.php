<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Email;

use Ordo\Automation\Model\Email\PendingUnsubscribeHeaderHolder;
use PHPUnit\Framework\TestCase;

class PendingUnsubscribeHeaderHolderTest extends TestCase
{
    public function testConsumeReturnsNullWhenNothingSet(): void
    {
        self::assertNull((new PendingUnsubscribeHeaderHolder())->consume());
    }

    public function testConsumeReturnsAndClearsTheSetValue(): void
    {
        $holder = new PendingUnsubscribeHeaderHolder();
        $holder->set('https://example.com/ordo/email/unsubscribe/token/42.email.sig');

        self::assertSame(
            'https://example.com/ordo/email/unsubscribe/token/42.email.sig',
            $holder->consume()
        );
        self::assertNull($holder->consume());
    }
}
