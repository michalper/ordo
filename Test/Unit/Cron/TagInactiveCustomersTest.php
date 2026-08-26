<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Cron\SendWinBackEmails;
use Ordo\Automation\Cron\TagInactiveCustomers;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class TagInactiveCustomersTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('having')->willReturnSelf();

        return $select;
    }

    public function testExecuteSkipsWhenLifecycleEmailsDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $tagManager = $this->createMock(CustomerTagManager::class);

        (new TagInactiveCustomers($config, $resourceConnection, $tagManager, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testExecuteTagsNewlyInactiveAndClearsRecovered(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);
        $config->method('getWinBackInactiveDays')->willReturn(90);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['5']);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('hasTag')->with(5, TagInactiveCustomers::TAG_INACTIVE)->willReturn(false);
        $tagManager->expects(self::once())->method('addTag')->with(5, TagInactiveCustomers::TAG_INACTIVE);
        $tagManager->method('getCustomerIdsWithTag')->with(TagInactiveCustomers::TAG_INACTIVE)->willReturn([9]);
        $tagManager->expects(self::exactly(2))->method('removeTag')->willReturnCallback(
            function (int $customerId, string $tag) {
                self::assertSame(9, $customerId);
                self::assertContains($tag, [TagInactiveCustomers::TAG_INACTIVE, SendWinBackEmails::TAG_WIN_BACK_SENT]);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('tagged 1 customers as inactive, cleared 1'));

        (new TagInactiveCustomers($config, $resourceConnection, $tagManager, $logger))->execute();
    }
}
