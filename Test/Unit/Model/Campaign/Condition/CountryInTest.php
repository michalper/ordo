<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Campaign\Condition\CountryIn;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CountryInTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeCondition(AdapterInterface $connection): CountryIn
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return new CountryIn($resourceConnection);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenCustomersBillingCountryIsInTheList(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchOne')->willReturn('PL');

        $condition = $this->makeCondition($connection);

        self::assertTrue($condition->isSatisfied(['customer_id' => 42], ['countries' => 'US,PL,DE']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomersBillingCountryIsNotInTheList(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchOne')->willReturn('FR');

        $condition = $this->makeCondition($connection);

        self::assertFalse($condition->isSatisfied(['customer_id' => 42], ['countries' => 'US,PL,DE']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerHasNoGridRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchOne')->willReturn(false);

        $condition = $this->makeCondition($connection);

        self::assertFalse($condition->isSatisfied(['customer_id' => 42], ['countries' => 'US,PL,DE']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $condition = $this->makeCondition($connection);

        self::assertFalse($condition->isSatisfied([], ['countries' => 'US,PL,DE']));
    }

    public function testNotSatisfiedWhenCountriesIsMissing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $condition = $this->makeCondition($connection);

        self::assertFalse($condition->isSatisfied(['customer_id' => 42], []));
    }

    /**
     * Matching is case-insensitive and tolerates whitespace around each code - an admin typing
     * "us, pl , de" must work the same as "US,PL,DE".
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCountryMatchingIsCaseInsensitiveAndTrimsWhitespace(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchOne')->willReturn('PL');

        $condition = $this->makeCondition($connection);

        self::assertTrue($condition->isSatisfied(['customer_id' => 42], ['countries' => ' us, pl , de ']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsReturnsMatchingCustomerIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchCol')->willReturn(['3', '7']);

        $condition = $this->makeCondition($connection);

        self::assertSame([3, 7], $condition->resolveCustomerIds(['countries' => 'US,PL']));
    }

    public function testResolveCustomerIdsFailsClosedWhenCountriesIsMissing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $condition = $this->makeCondition($connection);

        self::assertSame([], $condition->resolveCustomerIds([]));
    }

    public function testGetParamFieldsListsCountries(): void
    {
        $condition = $this->makeCondition($this->createStub(AdapterInterface::class));

        self::assertSame(['countries'], array_column($condition->getParamFields(), 'name'));
    }
}
