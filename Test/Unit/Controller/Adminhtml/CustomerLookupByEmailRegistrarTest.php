<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Ordo\Automation\Controller\Adminhtml\CustomerLookupByEmailRegistrar;
use PHPUnit\Framework\TestCase;

class CustomerLookupByEmailRegistrarTest extends TestCase
{
    public function testResolveAndRegisterReturnsNullWhenCustomerNotFound(): void
    {
        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $customerRepository->method('get')->willThrowException(new NoSuchEntityException(__('no such customer')));
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $registrar = new CustomerLookupByEmailRegistrar($customerRepository, $registry);

        self::assertNull($registrar->resolveAndRegister('nobody@example.com', 'ordo_gdpr'));
    }

    public function testResolveAndRegisterRegistersCustomerUnderGivenPrefix(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getEmail')->willReturn('jan@example.com');

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::once())->method('get')->with('jan@example.com')->willReturn($customer);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::exactly(2))->method('register')->willReturnMap([
            ['ordo_gdpr_customer_id', 42],
            ['ordo_gdpr_customer_email', 'jan@example.com'],
        ]);

        $registrar = new CustomerLookupByEmailRegistrar($customerRepository, $registry);

        self::assertSame($customer, $registrar->resolveAndRegister('jan@example.com', 'ordo_gdpr'));
    }
}
