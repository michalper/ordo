<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Cron\SendCheckoutAbandonmentReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendCheckoutAbandonmentRemindersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('having')->willReturnSelf();

        return $select;
    }

    /**
     * Same reasoning SendAbandonedCartRemindersTest's own stubFetchOne() documents - claimUnderLimit()
     * wraps its count check in GET_LOCK()/RELEASE_LOCK(), a raw SQL string fetchOne() call distinct
     * from the batch SELECT's own Select-object fetchOne()/fetchAll() calls.
     */
    private function stubFetchOne(AdapterInterface $connection, int $countResult): void
    {
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $countResult
        );
    }

    private function makeConfig(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isCheckoutAbandonmentEnabled')->willReturn(true);
        $config->method('getCheckoutAbandonmentDelayMinutes')->willReturn(60);
        $config->method('getCheckoutAbandonmentMinSubtotal')->willReturn(0.0);
        $config->method('getCheckoutAbandonmentMaxReminders')->willReturn(1);

        return $config;
    }

    private function makeResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null
    ): SendCheckoutAbandonmentReminders {
        return new SendCheckoutAbandonmentReminders(
            $config,
            $resourceConnection,
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class)),
            new ReminderLogStore($resourceConnection)
        );
    }

    public function testExecuteSkipsEntirelyWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCheckoutAbandonmentEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesTriggerForADueQuote(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['quote_id' => 10, 'customer_id' => 5, 'subtotal' => 150.0, 'reminders_sent' => 0],
        ]);
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('insert');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('checkout_abandoned', [
            'customer_id' => 5,
            'cart_subtotal' => 150.0,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 checkout_abandoned'));

        $this->makeCron($this->makeConfig(), $this->makeResourceConnection($connection), $dispatcher, $logger)
            ->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAQuoteAlreadyAtTheMaxReminderLimit(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['quote_id' => 10, 'customer_id' => 5, 'subtotal' => 150.0, 'reminders_sent' => 1],
        ]);
        // 1 already-logged reminder >= the configured max of 1 - claimUnderLimit() must refuse.
        $this->stubFetchOne($connection, 1);
        $connection->expects(self::never())->method('insert');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($this->makeConfig(), $this->makeResourceConnection($connection), $dispatcher)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsFailureAndContinuesWhenDispatchThrows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['quote_id' => 10, 'customer_id' => 5, 'subtotal' => 150.0, 'reminders_sent' => 0],
        ]);
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('insert');
        $connection->expects(self::once())->method('delete');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 checkout_abandoned'));

        $this->makeCron($this->makeConfig(), $this->makeResourceConnection($connection), $dispatcher, $logger)
            ->execute();
    }
}
