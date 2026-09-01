<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\CreditLimitCalculator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CreditLimitCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();

        return $select;
    }

    private function makeConnectionMock(): AdapterInterface
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());

        return $connection;
    }

    public function testGetCreditLimitReturnsAttributeValue(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn('5000');
        $customer->method('getCustomAttribute')->willReturn($attribute);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->with(42)->willReturn($customer);

        $calculator = new CreditLimitCalculator($this->createStub(ResourceConnection::class), $customerRepository);

        self::assertSame(5000.0, $calculator->getCreditLimit(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCreditLimitReturnsZeroWhenAttributeMissing(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

        $calculator = new CreditLimitCalculator($this->createStub(ResourceConnection::class), $customerRepository);

        self::assertSame(0.0, $calculator->getCreditLimit(42));
    }

    public function testGetUsedCreditSumsTotalDue(): void
    {
        $connection = $this->makeConnectionMock();
        $connection->method('fetchOne')->willReturn('1250.50');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $calculator = new CreditLimitCalculator($resourceConnection, $this->createStub(CustomerRepositoryInterface::class));

        self::assertSame(1250.5, $calculator->getUsedCredit(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetUtilizationPercentReturnsZeroWhenNoLimit(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

        $calculator = new CreditLimitCalculator($this->createStub(ResourceConnection::class), $customerRepository);

        self::assertSame(0.0, $calculator->getUtilizationPercent(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetUtilizationPercentComputesRatio(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn('1000');
        $customer->method('getCustomAttribute')->willReturn($attribute);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

        $connection = $this->makeConnectionMock();
        $connection->method('fetchOne')->willReturn('500');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $calculator = new CreditLimitCalculator($resourceConnection, $customerRepository);

        self::assertSame(50.0, $calculator->getUtilizationPercent(42));
    }

    public function testGetCustomerIdsWithCreditLimitReturnsEmptyWhenAttributeMissing(): void
    {
        $connection = $this->makeConnectionMock();
        $connection->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $calculator = new CreditLimitCalculator($resourceConnection, $this->createStub(CustomerRepositoryInterface::class));

        self::assertSame([], $calculator->getCustomerIdsWithCreditLimit());
    }

    public function testGetCustomerIdsWithCreditLimitReturnsIntArray(): void
    {
        $connection = $this->makeConnectionMock();
        $connection->method('fetchOne')->willReturn('9');
        $connection->method('fetchCol')->willReturn(['1', '3']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $calculator = new CreditLimitCalculator($resourceConnection, $this->createStub(CustomerRepositoryInterface::class));

        self::assertSame([1, 3], $calculator->getCustomerIdsWithCreditLimit());
    }
}
