<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Email;

use Magento\Framework\Encryption\EncryptorInterface;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\Email\UnsubscribeToken;
use Ordo\Automation\Model\Email\UnsubscribeTokenManager;
use PHPUnit\Framework\TestCase;

class UnsubscribeTokenManagerTest extends TestCase
{
    private const string SECRET = 'a-real-crypt-key';

    private function makeManager(): UnsubscribeTokenManager
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(
            fn (string $data): string => hash_hmac('sha256', $data, self::SECRET)
        );

        return new UnsubscribeTokenManager($encryptor);
    }

    public function testGeneratedTokenResolvesBackToTheSameCustomerAndChannel(): void
    {
        $manager = $this->makeManager();
        $token = $manager->generate(42, ConsentChannel::Email);

        $resolved = $manager->resolve($token);

        self::assertInstanceOf(UnsubscribeToken::class, $resolved);
        self::assertSame(42, $resolved->customerId);
        self::assertSame(ConsentChannel::Email, $resolved->channel);
    }

    public function testTamperedCustomerIdIsRejected(): void
    {
        $manager = $this->makeManager();
        $token = $manager->generate(42, ConsentChannel::Email);

        // Swap the plaintext customerId for a different one, keeping the original signature -
        // the signature no longer matches the (now different) claimed payload.
        $tampered = preg_replace('/^42\./', '99.', $token);

        self::assertNotNull($tampered);
        self::assertNull($manager->resolve($tampered));
    }

    public function testTamperedChannelIsRejected(): void
    {
        $manager = $this->makeManager();
        $token = $manager->generate(42, ConsentChannel::Email);

        $tampered = preg_replace('/\.email\./', '.sms.', $token);

        self::assertNotNull($tampered);
        self::assertNull($manager->resolve($tampered));
    }

    public function testForgedSignatureIsRejected(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->resolve('42.email.' . str_repeat('0', 64)));
    }

    public function testMalformedTokenShapeIsRejected(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->resolve('not-a-real-token'));
        self::assertNull($manager->resolve('42.email'));
        self::assertNull($manager->resolve('42.email.sig.extra'));
    }

    public function testNonNumericCustomerIdIsRejected(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->resolve('abc.email.' . str_repeat('0', 64)));
    }

    public function testUnknownChannelIsRejected(): void
    {
        $manager = $this->makeManager();

        self::assertNull($manager->resolve('42.carrier_pigeon.' . str_repeat('0', 64)));
    }

    public function testEmptyTokenIsRejected(): void
    {
        self::assertNull($this->makeManager()->resolve(''));
    }
}
