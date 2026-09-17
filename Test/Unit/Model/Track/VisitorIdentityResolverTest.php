<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use PHPUnit\Framework\TestCase;

class VisitorIdentityResolverTest extends TestCase
{
    private CustomerSession $customerSession;
    private CookieManagerInterface $cookieManager;
    private VisitorIdentityResolver $resolver;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->cookieManager = $this->createStub(CookieManagerInterface::class);
        $this->resolver = new VisitorIdentityResolver($this->customerSession, $this->cookieManager);
    }

    public function testResolveVisitorIdReturnsCookieValue(): void
    {
        $this->cookieManager->method('getCookie')->willReturn('visitor-1');

        self::assertSame('visitor-1', $this->resolver->resolveVisitorId());
    }

    public function testResolveVisitorIdReturnsEmptyStringWhenCookieAbsent(): void
    {
        $this->cookieManager->method('getCookie')->willReturn(null);

        self::assertSame('', $this->resolver->resolveVisitorId());
    }

    public function testHasNoIdentityIsTrueWhenNoVisitorIdAndNotLoggedIn(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        self::assertTrue($this->resolver->hasNoIdentity(''));
    }

    public function testHasNoIdentityIsFalseWhenVisitorIdPresent(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        self::assertFalse($this->resolver->hasNoIdentity('visitor-1'));
    }

    public function testHasNoIdentityIsFalseWhenLoggedIn(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);

        self::assertFalse($this->resolver->hasNoIdentity(''));
    }

    public function testResolveCustomerIdReturnsNullWhenAnonymous(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        self::assertNull($this->resolver->resolveCustomerId());
    }

    public function testResolveCustomerIdReturnsIdWhenLoggedIn(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        self::assertSame(42, $this->resolver->resolveCustomerId());
    }

    public function testValidateForCsrfReturnsTrueForAnonymousVisitor(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $request = $this->createStub(Http::class);

        self::assertTrue($this->resolver->validateForCsrf($request));
    }

    public function testValidateForCsrfAcceptsMatchingOrigin(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $request = $this->createStub(Http::class);
        $request->method('getHeader')->willReturnMap([
            ['Origin', 'https://shop.example.com'],
        ]);
        $request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertTrue($this->resolver->validateForCsrf($request));
    }

    public function testValidateForCsrfRejectsMismatchedOrigin(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $request = $this->createStub(Http::class);
        $request->method('getHeader')->willReturnMap([
            ['Origin', 'https://attacker.example.com'],
        ]);
        $request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertFalse($this->resolver->validateForCsrf($request));
    }

    public function testValidateForCsrfFallsBackToRefererWhenOriginMissing(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $request = $this->createStub(Http::class);
        $request->method('getHeader')->willReturnMap([
            ['Origin', false],
            ['Referer', 'https://shop.example.com/some/page'],
        ]);
        $request->method('getHttpHost')->willReturn('shop.example.com');

        self::assertTrue($this->resolver->validateForCsrf($request));
    }

    public function testValidateForCsrfRejectsWhenNeitherOriginNorRefererPresent(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $request = $this->createStub(Http::class);
        $request->method('getHeader')->willReturn(false);

        self::assertFalse($this->resolver->validateForCsrf($request));
    }
}
