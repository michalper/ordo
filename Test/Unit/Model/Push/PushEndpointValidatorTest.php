<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Push;

use Ordo\Automation\Model\Push\PushEndpointValidator;
use PHPUnit\Framework\TestCase;

class PushEndpointValidatorTest extends TestCase
{
    private PushEndpointValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PushEndpointValidator();
    }

    public function testRejectsNonHttpsScheme(): void
    {
        self::assertFalse($this->validator->isAllowed('http://fcm.googleapis.com/fcm/send/abc'));
    }

    public function testRejectsMalformedUrl(): void
    {
        self::assertFalse($this->validator->isAllowed('not a url'));
    }

    /**
     * The canonical cloud metadata SSRF target - link-local range, must never be allowed
     * regardless of scheme.
     */
    public function testRejectsLinkLocalIpLiteral(): void
    {
        self::assertFalse($this->validator->isAllowed('https://169.254.169.254/latest/meta-data/'));
    }

    public function testRejectsPrivateIpLiteral(): void
    {
        self::assertFalse($this->validator->isAllowed('https://10.0.0.5/internal'));
        self::assertFalse($this->validator->isAllowed('https://192.168.1.1/'));
        self::assertFalse($this->validator->isAllowed('https://172.16.0.1/'));
    }

    public function testRejectsLoopbackIpLiteral(): void
    {
        self::assertFalse($this->validator->isAllowed('https://127.0.0.1/'));
        self::assertFalse($this->validator->isAllowed('https://[::1]/'));
    }

    public function testRejectsUnresolvableHostname(): void
    {
        self::assertFalse($this->validator->isAllowed('https://this-host-does-not-exist.invalid/push'));
    }

    /**
     * A hostname that resolves via real DNS to a loopback address ("localtest.me" is a
     * well-known public DNS entry that always resolves to 127.0.0.1) - covers the branch inside
     * the resolved-records loop itself, distinct from testRejectsLoopbackIpLiteral() which never
     * reaches DNS resolution at all because it's given a literal IP.
     */
    public function testRejectsHostnameThatResolvesToALoopbackAddress(): void
    {
        self::assertFalse($this->validator->isAllowed('https://localtest.me/push'));
    }

    public function testAllowsAPublicHttpsHostname(): void
    {
        self::assertTrue($this->validator->isAllowed('https://fcm.googleapis.com/fcm/send/abc123'));
    }

    /**
     * resolvePinnedIp() is what PushSender pins its actual HTTP connection to via
     * CURLOPT_RESOLVE - for an IP literal endpoint it must return that same literal back.
     */
    public function testResolvePinnedIpReturnsTheLiteralForAPublicIpEndpoint(): void
    {
        self::assertSame('203.0.113.5', $this->validator->resolvePinnedIp('https://203.0.113.5/push'));
    }

    public function testResolvePinnedIpReturnsNullForARejectedEndpoint(): void
    {
        self::assertNull($this->validator->resolvePinnedIp('https://169.254.169.254/latest/meta-data/'));
    }
}
