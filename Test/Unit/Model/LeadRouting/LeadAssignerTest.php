<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\LeadRouting;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\LeadRouting\LeadAssigner;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class LeadAssignerTest extends TestCase
{
    private function makeRule(int $entityId, array $reps): LeadRoutingRule
    {
        $rule = $this->createStub(LeadRoutingRule::class);
        $rule->method('getEntityId')->willReturn($entityId);
        $rule->method('getReps')->willReturn(json_encode($reps));

        return $rule;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAssignPicksTheFirstRepWhenNoStateRowExistsYet(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(self::once())->method('commit');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $setAttributes = [];
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('setCustomAttribute')->willReturnCallback(
            function (string $code, $value) use ($customer, &$setAttributes) {
                $setAttributes[$code] = $value;
                return $customer;
            }
        );

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::once())->method('getById')->with(42)->willReturn($customer);
        $customerRepository->expects(self::once())->method('save')->with($customer);

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);
        $rule = $this->makeRule(1, [
            ['email' => 'first@example.com', 'name' => 'First Rep', 'phone' => '111'],
            ['email' => 'second@example.com', 'name' => 'Second Rep', 'phone' => '222'],
        ]);

        $assigner->assign(42, $rule);

        self::assertSame([
            AddSalesRepAttributes::ATTRIBUTE_REP_NAME => 'First Rep',
            AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL => 'first@example.com',
            AddSalesRepAttributes::ATTRIBUTE_REP_PHONE => '111',
        ], $setAttributes);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAssignCyclesToTheNextRepWhenAStateRowAlreadyExists(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        // Position 0 was assigned last - the next call should wrap to position 1.
        $connection->method('fetchOne')->willReturn('0');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $setAttributes = [];
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('setCustomAttribute')->willReturnCallback(
            function (string $code, $value) use ($customer, &$setAttributes) {
                $setAttributes[$code] = $value;
                return $customer;
            }
        );

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);
        $rule = $this->makeRule(1, [
            ['email' => 'first@example.com'],
            ['email' => 'second@example.com'],
        ]);

        $assigner->assign(42, $rule);

        self::assertSame('second@example.com', $setAttributes[AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAssignWrapsAroundBackToTheFirstRepAfterTheLastOne(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        // Position 1 (the last of 2 reps) was assigned last - wraps to position 0.
        $connection->method('fetchOne')->willReturn('1');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $setAttributes = [];
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('setCustomAttribute')->willReturnCallback(
            function (string $code, $value) use ($customer, &$setAttributes) {
                $setAttributes[$code] = $value;
                return $customer;
            }
        );

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);
        $rule = $this->makeRule(1, [
            ['email' => 'first@example.com'],
            ['email' => 'second@example.com'],
        ]);

        $assigner->assign(42, $rule);

        self::assertSame('first@example.com', $setAttributes[AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL]);
    }

    public function testAssignNoOpsWhenTheRuleHasNoReps(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::never())->method('getById');

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);
        $assigner->assign(42, $this->makeRule(1, []));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasAssignedRepIsTrueForANonBlankRepEmail(): void
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn('rep@example.com');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn($attribute);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $assigner = new LeadAssigner($resourceConnection, $customerRepository);

        self::assertTrue($assigner->hasAssignedRep($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasAssignedRepIsFalseWhenNoAttributeExists(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $assigner = new LeadAssigner($resourceConnection, $customerRepository);

        self::assertFalse($assigner->hasAssignedRep($customer));
    }
}
