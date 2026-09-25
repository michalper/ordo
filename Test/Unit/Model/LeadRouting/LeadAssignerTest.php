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
    private function stubFetchOne(AdapterInterface $connection, $positionResult): void
    {
        // assign() now wraps the position lookup in GET_LOCK()/RELEASE_LOCK() - a raw SQL string
        // first arg to fetchOne(), unlike the position query's own Select object - so the mock
        // needs to answer both distinctly instead of one flat ->willReturn($v) for every call.
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $positionResult
        );
    }

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
        $this->stubFetchOne($connection, false);
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
        $this->stubFetchOne($connection, '0');

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
        $this->stubFetchOne($connection, '1');

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

    /**
     * Regression: a second qualifying event racing in for the same customer (e.g.
     * customer_register_success and ordo_customer_score_threshold_crossed close together) used
     * to be able to consume a round-robin turn even though a rep was already assigned in the
     * meantime, since only the caller's own pre-lock hasAssignedRep() check guarded against that.
     * assign() now re-checks with a fresh customer load, under the lock, before ever touching the
     * round-robin state.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAssignDoesNothingWhenTheCustomerAlreadyHasARepOnceReCheckedUnderTheLock(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->stubFetchOne($connection, false);
        $connection->expects(self::never())->method('select');
        $connection->expects(self::once())->method('query')->with(self::stringContains('RELEASE_LOCK'), self::anything());

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn('already-assigned@example.com');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn($attribute);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::once())->method('getById')->with(42)->willReturn($customer);
        $customerRepository->expects(self::never())->method('save');

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);

        $assigner->assign(42, $this->makeRule(1, [['email' => 'first@example.com']]));
    }

    /**
     * Regression: assign() must fail closed (never assign) rather than proceed unlocked when a
     * concurrent process already holds the same per-customer named lock.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAssignDoesNothingWhenTheLockCannotBeAcquired(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 0 : false
        );
        $connection->expects(self::never())->method('query');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects(self::never())->method('getById');

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);

        $assigner->assign(42, $this->makeRule(1, [['email' => 'first@example.com']]));
    }

    /**
     * A malformed reps JSON (a null entry rather than a real rep object at the position the
     * round-robin pointer landed on) makes nextRep() itself return null - assign() must bail out
     * without touching the customer rather than writing bogus custom-attribute values.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAssignDoesNothingWhenTheRepAtTheComputedPositionIsNull(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->stubFetchOne($connection, false);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->expects(self::once())->method('commit');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);
        $customerRepository->expects(self::never())->method('save');

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);

        // Position 0 (no state row yet) is null instead of a rep object.
        $assigner->assign(42, $this->makeRule(1, [null, ['email' => 'second@example.com']]));
    }

    /**
     * A failure writing the round-robin pointer must roll back the transaction and propagate,
     * not swallow the error and silently skip the assignment.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAssignRollsBackAndRethrowsWhenAdvancingTheRoundRobinPointerFails(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->stubFetchOne($connection, false);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        // Only the round-robin pointer's own INSERT should fail - RELEASE_LOCK in assign()'s own
        // finally block must still be allowed to run without also throwing, same as a real
        // connection would behave.
        $connection->method('query')->willReturnCallback(function (string $sql) {
            if (str_starts_with($sql, 'INSERT INTO')) {
                throw new \RuntimeException('deadlock');
            }
            return null;
        });
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);

        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

        $assigner = new LeadAssigner($resourceConnection, $customerRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deadlock');

        $assigner->assign(42, $this->makeRule(1, [['email' => 'first@example.com']]));
    }

    #[AllowMockObjectsWithoutExpectations]
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
