<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Ordo\Automation\Model\Campaign\CustomerTimezoneResolver;
use Ordo\Automation\Setup\Patch\Data\AddCustomerTimezoneAttribute;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CustomerTimezoneResolverTest extends TestCase
{
    private CustomerRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $customerRepository;
    private TimezoneInterface&\PHPUnit\Framework\MockObject\MockObject $timezone;
    private CustomerTimezoneResolver $resolver;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->resolver = new CustomerTimezoneResolver($this->customerRepository, $this->timezone);
    }

    private function makeCustomer(?string $timezoneAttributeValue): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);

        if ($timezoneAttributeValue === null) {
            $customer->method('getCustomAttribute')->willReturn(null);
            return $customer;
        }

        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn($timezoneAttributeValue);
        $customer->method('getCustomAttribute')->willReturnMap([
            [AddCustomerTimezoneAttribute::ATTRIBUTE_CODE, $attribute],
        ]);

        return $customer;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveUsesTheCustomerAttributeWhenSet(): void
    {
        $this->customerRepository->expects(self::once())->method('getById')->with(42)->willReturn($this->makeCustomer('Europe/Warsaw'));
        $this->timezone->expects(self::never())->method('getConfigTimezone');

        $result = $this->resolver->resolve(42, 1);

        self::assertSame('Europe/Warsaw', $result->getName());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveFallsBackToStoreTimezoneWhenAttributeUnset(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->makeCustomer(null));
        $this->timezone->expects(self::once())->method('getConfigTimezone')->with('store', 1)->willReturn('America/New_York');

        $result = $this->resolver->resolve(42, 1);

        self::assertSame('America/New_York', $result->getName());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveFallsBackToStoreTimezoneWhenAttributeIsMalformed(): void
    {
        $this->customerRepository->expects(self::once())->method('getById')->with(42)->willReturn($this->makeCustomer('not-a-real-zone'));
        $this->timezone->method('getConfigTimezone')->willReturn('UTC');

        $result = $this->resolver->resolve(42, 1);

        self::assertSame('UTC', $result->getName());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveFallsBackToStoreTimezoneWhenCustomerLookupThrows(): void
    {
        $this->customerRepository->method('getById')->willThrowException(new \Exception('no such customer'));
        $this->timezone->method('getConfigTimezone')->willReturn('UTC');

        $result = $this->resolver->resolve(999, 1);

        self::assertSame('UTC', $result->getName());
    }
}
