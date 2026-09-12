<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Cron\SendBrowseAbandonmentReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class SendBrowseAbandonmentRemindersTest extends TestCase
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

    private function makeConnection(array $rows): AdapterInterface
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('quoteIdentifier')->willReturnCallback(fn (string $s) => "`{$s}`");
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $text, $value) => str_replace('?', "'{$value}'", $text)
        );
        $connection->method('fetchAll')->willReturn($rows);

        return $connection;
    }

    private function makeResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isBrowseAbandonmentEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesCampaignForViewedProduct(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isBrowseAbandonmentEnabled')->willReturn(true);
        $config->method('getBrowseAbandonmentDelayMinutes')->willReturn(60);
        $config->method('getBrowseAbandonmentMaxReminders')->willReturn(1);

        $connection = $this->makeConnection([
            ['customer_id' => 5, 'event_key' => 'SKU-1', 'reminders_sent' => 0],
        ]);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->makeResourceConnection($connection);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('browse_abandoned', [
            'customer_id' => 5,
            'product_sku' => 'SKU-1',
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 browse abandonment triggers'));

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorAndRollsBackClaimWhenDispatchThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isBrowseAbandonmentEnabled')->willReturn(true);
        $config->method('getBrowseAbandonmentDelayMinutes')->willReturn(60);
        $config->method('getBrowseAbandonmentMaxReminders')->willReturn(1);

        $connection = $this->makeConnection([
            ['customer_id' => 5, 'event_key' => 'SKU-1', 'reminders_sent' => 0],
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

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesNothingWhenNoRowsFound(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isBrowseAbandonmentEnabled')->willReturn(true);
        $config->method('getBrowseAbandonmentDelayMinutes')->willReturn(60);
        $config->method('getBrowseAbandonmentMaxReminders')->willReturn(1);

        $connection = $this->makeConnection([]);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->makeResourceConnection($connection);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 browse abandonment triggers'));

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null
    ): SendBrowseAbandonmentReminders {
        return new SendBrowseAbandonmentReminders(
            $config,
            $resourceConnection,
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class))
        );
    }
}
