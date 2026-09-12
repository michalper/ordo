<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Webhook;

use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;
use PHPUnit\Framework\TestCase;

class WebhookSignatureValidatorTest extends TestCase
{
    private const string SECRET = 'a-real-webhook-secret';

    public function testSignProducesAValidSignature(): void
    {
        $validator = new WebhookSignatureValidator();
        $body = '{"event":"order_placed"}';

        self::assertTrue($validator->isValid(self::SECRET, $body, $validator->sign(self::SECRET, $body)));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $validator = new WebhookSignatureValidator();
        $signature = $validator->sign(self::SECRET, '{"event":"order_placed"}');

        self::assertFalse($validator->isValid(self::SECRET, '{"event":"tampered"}', $signature));
    }

    public function testForgedSignatureIsRejected(): void
    {
        $validator = new WebhookSignatureValidator();

        self::assertFalse(
            $validator->isValid(self::SECRET, '{"event":"order_placed"}', 'sha256=' . str_repeat('0', 64))
        );
    }

    public function testEmptySecretIsRejected(): void
    {
        $validator = new WebhookSignatureValidator();
        $body = '{"event":"order_placed"}';

        self::assertFalse($validator->isValid('', $body, $validator->sign(self::SECRET, $body)));
    }

    public function testMissingSignaturePrefixIsRejected(): void
    {
        $validator = new WebhookSignatureValidator();
        $body = '{"event":"order_placed"}';

        self::assertFalse($validator->isValid(self::SECRET, $body, hash_hmac('sha256', $body, self::SECRET)));
    }
}
