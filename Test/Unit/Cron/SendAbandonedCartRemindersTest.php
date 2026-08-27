<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendAbandonedCartReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class SendAbandonedCartRemindersTest extends TestCase
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

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    public function testExecuteSendsReminderAndDispatchesCampaignForKnownCustomer(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 10,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with('cart_abandoned', [
            'customer_id' => 5,
            'cart_subtotal' => 150.0,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 abandoned cart reminders'));

        $this->makeCron($config, $resourceConnection, $dispatcher, $logger)->execute();
    }

    public function testExecuteSkipsCampaignDispatchForGuestQuote(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 11,
                'customer_id' => null,
                'customer_email' => 'guest@example.com',
                'customer_firstname' => null,
                'subtotal' => 60.0,
            ],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $dispatcher)->execute();
    }

    public function testExecuteLogsErrorWhenSendingReminderThrows(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('getAbandonedCartDelayMinutes')->willReturn(120);
        $config->method('getAbandonedCartMinSubtotal')->willReturn(0.0);
        $config->method('getAbandonedCartMaxReminders')->willReturn(1);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 10,
                'customer_id' => 5,
                'customer_email' => 'jan@example.com',
                'customer_firstname' => 'Jan',
                'subtotal' => 150.0,
            ],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quoteFactory->method('create')->willThrowException(new \RuntimeException('quote load failed'));

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            $quoteFactory,
            $this->createMock(TransportBuilder::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateInterface::class),
            $dispatcher,
            $logger
        ))->execute();
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null
    ): SendAbandonedCartReminders {
        $quoteItem = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $quoteItem->method('getName')->willReturn('Widget');
        $quoteItem->method('getQty')->willReturn(2.0);

        $quote = $this->createMock(Quote::class);
        $quote->method('load')->willReturnSelf();
        $quote->method('getAllVisibleItems')->willReturn([$quoteItem]);

        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quoteFactory->method('create')->willReturn($quote);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($this->createMock(TransportInterface::class));

        return new SendAbandonedCartReminders(
            $config,
            $resourceConnection,
            $quoteFactory,
            $transportBuilder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $dispatcher ?? $this->createMock(CampaignDispatcher::class),
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }
}
