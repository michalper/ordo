<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Model\CustomerStoreResolver;
use PHPUnit\Framework\TestCase;

class CustomerStoreResolverTest extends TestCase
{
    public function testResolveStoreIdCastsANumericStringToInt(): void
    {
        // The real-world case this class exists for: CustomerInterface::getStoreId() has no
        // return type on the interface, and a real customer repository fetch returns it as a
        // string, not an int.
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn('7');

        self::assertSame(7, (new CustomerStoreResolver())->resolveStoreId($customer));
    }

    public function testResolveStoreIdPassesThroughARealInt(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(7);

        self::assertSame(7, (new CustomerStoreResolver())->resolveStoreId($customer));
    }

    public function testResolveStoreIdKeepsNullAsNullNotZero(): void
    {
        // A customer with no store on record must stay null (meaning "use the ambient default
        // store" to every caller here) - (int) null would be 0, a real, different store id.
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(null);

        self::assertNull((new CustomerStoreResolver())->resolveStoreId($customer));
    }

    public function testResolveWebsiteIdCastsANumericStringToInt(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getWebsiteId')->willReturn('3');

        self::assertSame(3, (new CustomerStoreResolver())->resolveWebsiteId($customer));
    }

    public function testResolveWebsiteIdPassesThroughARealInt(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getWebsiteId')->willReturn(3);

        self::assertSame(3, (new CustomerStoreResolver())->resolveWebsiteId($customer));
    }

    public function testResolveWebsiteIdKeepsNullAsNullNotZero(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getWebsiteId')->willReturn(null);

        self::assertNull((new CustomerStoreResolver())->resolveWebsiteId($customer));
    }
}
