<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Sms;

use Ordo\Automation\Model\Sms\TwilioSignatureValidator;
use PHPUnit\Framework\TestCase;
use Twilio\Security\RequestValidator;

class TwilioSignatureValidatorTest extends TestCase
{
    private const string AUTH_TOKEN = 'secret-token';
    private const string URL = 'https://example.com/ordo/sms/reply';

    public function testIsValidReturnsTrueForARealSignature(): void
    {
        $params = ['From' => '+15551234567', 'Body' => 'hi'];
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature(self::URL, $params);

        self::assertTrue((new TwilioSignatureValidator())->isValid(self::AUTH_TOKEN, $signature, self::URL, $params));
    }

    public function testIsValidReturnsFalseForAForgedSignature(): void
    {
        $params = ['From' => '+15551234567', 'Body' => 'hi'];

        self::assertFalse(
            (new TwilioSignatureValidator())->isValid(self::AUTH_TOKEN, 'forged', self::URL, $params)
        );
    }

    public function testIsValidReturnsFalseForAnEmptySignature(): void
    {
        self::assertFalse((new TwilioSignatureValidator())->isValid(self::AUTH_TOKEN, '', self::URL, []));
    }

    /**
     * Regression: Twilio\Security\RequestValidator has no special-case rejection for an empty
     * *key* (only an empty signature) - a forged signature computed with the empty-string key
     * would otherwise validate successfully before an Auth Token is ever configured.
     */
    public function testIsValidReturnsFalseForAnEmptyAuthTokenEvenWithAMatchingForgedSignature(): void
    {
        $params = ['From' => '+15551234567', 'Body' => 'hi'];
        $forgedSignature = (new RequestValidator(''))->computeSignature(self::URL, $params);

        self::assertFalse((new TwilioSignatureValidator())->isValid('', $forgedSignature, self::URL, $params));
    }
}
