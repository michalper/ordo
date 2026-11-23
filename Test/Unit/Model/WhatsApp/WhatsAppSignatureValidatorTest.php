<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\WhatsApp;

use Ordo\Automation\Model\Webhook\WebhookSignatureValidator;
use Ordo\Automation\Model\WhatsApp\WhatsAppSignatureValidator;
use PHPUnit\Framework\TestCase;

/**
 * Uses a real HMAC-SHA256 computation (via a real, undoubled WebhookSignatureValidator - see
 * WhatsAppSignatureValidator's own docblock for why it delegates to that class rather than
 * re-implementing the same comparison) - same reasoning as
 * Email\SendGridSignatureValidatorTest's use of a real ECDSA keypair: proves the actual call
 * site works against a genuinely correct signature, not just a hand-faked one.
 */
class WhatsAppSignatureValidatorTest extends TestCase
{
    private const string APP_SECRET = 'a-real-app-secret';

    private function sign(string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, self::APP_SECRET);
    }

    private function makeValidator(): WhatsAppSignatureValidator
    {
        return new WhatsAppSignatureValidator(new WebhookSignatureValidator());
    }

    public function testValidSignatureIsAccepted(): void
    {
        $validator = $this->makeValidator();
        $body = '{"entry":[]}';

        self::assertTrue($validator->isValid(self::APP_SECRET, $body, $this->sign($body)));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $validator = $this->makeValidator();
        $signature = $this->sign('{"entry":[]}');

        self::assertFalse($validator->isValid(self::APP_SECRET, '{"entry":["tampered"]}', $signature));
    }

    public function testForgedSignatureIsRejected(): void
    {
        $validator = $this->makeValidator();

        self::assertFalse($validator->isValid(self::APP_SECRET, '{"entry":[]}', 'sha256=' . str_repeat('0', 64)));
    }

    public function testEmptyAppSecretIsRejected(): void
    {
        $validator = $this->makeValidator();

        self::assertFalse($validator->isValid('', '{"entry":[]}', $this->sign('{"entry":[]}')));
    }

    public function testMissingSignaturePrefixIsRejected(): void
    {
        $validator = $this->makeValidator();
        $body = '{"entry":[]}';

        self::assertFalse($validator->isValid(self::APP_SECRET, $body, hash_hmac('sha256', $body, self::APP_SECRET)));
    }
}
