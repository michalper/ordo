<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock;

use Ordo\Automation\Model\ContentBlock\HostResolver;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real gethostbynamel() rather than mocking it - there's nothing to fake here
 * (no network call this module controls, see the class's own docblock), and both cases below
 * are deterministic everywhere: localhost always resolves, and RFC 2606 reserves the .invalid
 * TLD specifically so a lookup against it is guaranteed to never succeed, in CI or anywhere else.
 */
class HostResolverTest extends TestCase
{
    public function testResolveReturnsAtLeastOneAddressForAKnownResolvableHost(): void
    {
        $addresses = (new HostResolver())->resolve('localhost');

        self::assertNotEmpty($addresses);
        self::assertContains('127.0.0.1', $addresses);
    }

    public function testResolveReturnsAnEmptyArrayForAHostThatCanNeverResolve(): void
    {
        $addresses = (new HostResolver())->resolve('this-host-will-never-resolve.invalid');

        self::assertSame([], $addresses);
    }
}
