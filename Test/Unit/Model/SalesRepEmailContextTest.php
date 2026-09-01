<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Seed unit test for the Test/ directory — establishes the mocking pattern
 * (CustomerRepositoryInterface + AttributeInterface) the rest of the suite follows.
 */
class SalesRepEmailContextTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private StoreManagerInterface&MockObject $storeManager;
    private SalesRepEmailContext $context;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->context = new SalesRepEmailContext($this->customerRepository, $this->storeManager);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsAssignedRepWhenNameAndEmailAreSet(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturnMap([
            [AddSalesRepAttributes::ATTRIBUTE_REP_NAME, $this->attribute('Anna Kowalski')],
            [AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL, $this->attribute('anna@example.com')],
            [AddSalesRepAttributes::ATTRIBUTE_REP_PHONE, $this->attribute('+1 555 0100')],
        ]);
        $this->customerRepository->method('getById')->with(42)->willReturn($customer);

        $result = $this->context->getForCustomer(42);

        self::assertSame('Anna Kowalski', $result['sender_name']);
        self::assertSame('anna@example.com', $result['sender_email']);
        self::assertSame('+1 555 0100', $result['sender_phone']);
        self::assertTrue($result['has_assigned_rep']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFallsBackToStoreNameWhenNoRepIsAssigned(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);
        $this->customerRepository->method('getById')->with(7)->willReturn($customer);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getName')->willReturn('Acme Supplies');
        $this->storeManager->method('getStore')->willReturn($store);

        $result = $this->context->getForCustomer(7);

        self::assertSame('Acme Supplies Team', $result['sender_name']);
        self::assertSame('', $result['sender_email']);
        self::assertFalse($result['has_assigned_rep']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFallsBackToGenericTeamWhenStoreLookupThrows(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);
        $this->customerRepository->method('getById')->with(8)->willReturn($customer);

        $this->storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $result = $this->context->getForCustomer(8);

        self::assertSame('our team', $result['sender_name']);
        self::assertFalse($result['has_assigned_rep']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFallsBackWhenCustomerDoesNotExist(): void
    {
        $this->customerRepository->method('getById')
            ->with(999)
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $store = $this->createStub(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $this->storeManager->method('getStore')->willReturn($store);

        $result = $this->context->getForCustomer(999);

        self::assertSame('our team', $result['sender_name']);
        self::assertFalse($result['has_assigned_rep']);
    }

    private function attribute(string $value): AttributeInterface
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn($value);

        return $attribute;
    }
}
