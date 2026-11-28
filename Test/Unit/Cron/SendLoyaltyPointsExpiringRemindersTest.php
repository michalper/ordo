<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Cron\SendLoyaltyPointsExpiringReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendLoyaltyPointsExpiringRemindersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeConnection(array $rows, int $alreadyLoggedCount = 0): AdapterInterface
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('quoteIdentifier')->willReturnCallback(fn (string $s) => "`{$s}`");
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $text, $value) => str_replace('?', "'{$value}'", $text)
        );
        $connection->method('fetchAll')->willReturn($rows);
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $alreadyLoggedCount
        );

        return $connection;
    }

    private function makeResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    public function testExecuteSkipsWhenLoyaltyPointsDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyPointsEnabled')->willReturn(false);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(30);
        $config->method('getLoyaltyPointsExpiryWarningDays')->willReturn(5);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    public function testExecuteSkipsWhenExpiryDaysIsZero(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(0);
        $config->method('getLoyaltyPointsExpiryWarningDays')->willReturn(5);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    public function testExecuteSkipsWhenWarningDaysIsZero(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(30);
        $config->method('getLoyaltyPointsExpiryWarningDays')->willReturn(0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesTriggerForDueLedgerRow(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(30);
        $config->method('getLoyaltyPointsExpiryWarningDays')->willReturn(5);

        $connection = $this->makeConnection([
            ['ledger_id' => 42, 'customer_id' => 5, 'points_delta' => 150],
        ]);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->makeResourceConnection($connection);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->with(CampaignTriggerInterface::TRIGGER_LOYALTY_POINTS_EXPIRING, [
                'customer_id' => 5,
                'at_risk_points' => 150,
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with(self::stringContains('1 loyalty points expiring triggers'));

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorAndRollsBackClaimWhenDispatchThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(30);
        $config->method('getLoyaltyPointsExpiryWarningDays')->willReturn(5);

        $connection = $this->makeConnection([
            ['ledger_id' => 42, 'customer_id' => 5, 'points_delta' => 150],
        ]);
        $connection->expects(self::once())->method('insert');
        $connection->expects(self::once())->method('delete');

        $resourceConnection = $this->makeResourceConnection($connection);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('dispatch failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    /**
     * Regression: a plain insert-then-dispatch let two overlapping runs (both selecting the same
     * due ledger rows before either logged) dispatch the same row twice - the claim's atomic
     * re-check must make the losing run skip instead of inserting a second log row.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNotDispatchWhenLedgerRowWasAlreadyClaimed(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(30);
        $config->method('getLoyaltyPointsExpiryWarningDays')->willReturn(5);

        $connection = $this->makeConnection(
            [['ledger_id' => 42, 'customer_id' => 5, 'points_delta' => 150]],
            alreadyLoggedCount: 1
        );
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->makeResourceConnection($connection);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with(self::stringContains('0 loyalty points expiring triggers'));

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesNothingWhenNoRowsFound(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(30);
        $config->method('getLoyaltyPointsExpiryWarningDays')->willReturn(5);

        $connection = $this->makeConnection([]);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->makeResourceConnection($connection);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with(self::stringContains('0 loyalty points expiring triggers'));

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null
    ): SendLoyaltyPointsExpiringReminders {
        return new SendLoyaltyPointsExpiringReminders(
            $config,
            $resourceConnection,
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class)),
            new ReminderLogStore($resourceConnection)
        );
    }
}
