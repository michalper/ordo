<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl;

use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use PHPUnit\Framework\TestCase;

class CustomerIdResolverTest extends TestCase
{
    public function testResolvesNullWhenContextIsNotTheCustomerAwareSubtype(): void
    {
        // Fails closed (guest) for an unexpected context type, same posture as an unknown
        // condition/action type elsewhere in this module - never a type Magento's real GraphQL
        // dispatch would actually pass, but the base interface's own resolve() signature doesn't
        // guarantee it statically.
        $context = $this->createStub(BaseContextInterface::class);

        self::assertNull((new CustomerIdResolver())->resolveCustomerId($context));
    }

    public function testResolvesNullForGuest(): void
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn(0);

        self::assertNull((new CustomerIdResolver())->resolveCustomerId($context));
    }

    public function testResolvesNullWhenUserIdIsNull(): void
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn(null);

        self::assertNull((new CustomerIdResolver())->resolveCustomerId($context));
    }

    public function testResolvesRealCustomerId(): void
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn(42);

        self::assertSame(42, (new CustomerIdResolver())->resolveCustomerId($context));
    }
}
