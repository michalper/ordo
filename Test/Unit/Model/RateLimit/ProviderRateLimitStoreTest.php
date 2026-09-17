<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\RateLimit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Model\RateLimit\ProviderRateLimitStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ProviderRateLimitStoreTest extends TestCase
{
    private function makeStore(AdapterInterface $connection): ProviderRateLimitStore
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return new ProviderRateLimitStore($resourceConnection);
    }

    /**
     * MySQL's INSERT..ON DUPLICATE KEY UPDATE reports 1 affected row for a fresh INSERT - a
     * never-before-seen channel is always allowed, since there's nothing to space out from yet.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimReturnsTrueWhenTheChannelRowIsFreshlyInserted(): void
    {
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('query')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE'), self::isArray())
            ->willReturn($statement);

        self::assertTrue($this->makeStore($connection)->claim('twilio', 1_000_000));
    }

    /**
     * rowCount() 2 means the UPDATE branch ran and actually changed the value - this call
     * arrived at/after the reserved slot and won the claim.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimReturnsTrueWhenTheUpdateActuallyChangesTheValue(): void
    {
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(2);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('query')->willReturn($statement);

        self::assertTrue($this->makeStore($connection)->claim('twilio', 1_000_000));
    }

    /**
     * rowCount() 0 means the UPDATE branch ran but the IF() left the value unchanged - another
     * caller already holds the current window, too soon to claim.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimReturnsFalseWhenTheUpdateLeavesTheValueUnchanged(): void
    {
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(0);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('query')->willReturn($statement);

        self::assertFalse($this->makeStore($connection)->claim('twilio', 1_000_000));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testClaimBindsTheChannelAndComputedInterval(): void
    {
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(1);

        $capturedBind = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('query')->willReturnCallback(
            function (string $sql, array $bind) use ($statement, &$capturedBind) {
                $capturedBind = $bind;
                return $statement;
            }
        );

        $this->makeStore($connection)->claim('push', 200_000);

        self::assertSame('push', $capturedBind['channel']);
        self::assertSame($capturedBind['next'], $capturedBind['next2']);
        self::assertGreaterThanOrEqual($capturedBind['now'], $capturedBind['next']);
    }
}
