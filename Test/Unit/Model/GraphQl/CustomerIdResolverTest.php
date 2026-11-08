<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl;

use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use PHPUnit\Framework\TestCase;

class CustomerIdResolverTest extends TestCase
{
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
