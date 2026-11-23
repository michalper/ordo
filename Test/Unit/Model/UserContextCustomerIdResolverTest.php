<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\UserContextCustomerIdResolver;
use PHPUnit\Framework\TestCase;

class UserContextCustomerIdResolverTest extends TestCase
{
    public function testReturnsTheAuthenticatedCustomerId(): void
    {
        $userContext = $this->createStub(UserContextInterface::class);
        $userContext->method('getUserId')->willReturn(42);

        self::assertSame(42, (new UserContextCustomerIdResolver())->requireCustomerId($userContext));
    }

    public function testThrowsWhenUnauthenticated(): void
    {
        $userContext = $this->createStub(UserContextInterface::class);
        $userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);

        (new UserContextCustomerIdResolver())->requireCustomerId($userContext);
    }

    /**
     * Regression: confirmed live against a real GraphQL request - UserContextInterface returns 0
     * for a guest, not null.
     */
    public function testThrowsWhenUserContextReturnsZero(): void
    {
        $userContext = $this->createStub(UserContextInterface::class);
        $userContext->method('getUserId')->willReturn(0);

        $this->expectException(NoSuchEntityException::class);

        (new UserContextCustomerIdResolver())->requireCustomerId($userContext);
    }
}
