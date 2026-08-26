<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendOfferExpiryReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Offer;
use Ordo\Automation\Model\ResourceModel\Offer\Collection;
use Ordo\Automation\Model\ResourceModel\Offer\CollectionFactory;
use Ordo\Automation\Model\SalesRepEmailContext;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class SendOfferExpiryRemindersTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isOfferReminderEnabled')->willReturn(false);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->expects(self::never())->method('create');

        $this->makeCron($config, $collectionFactory, $this->createMock(ResourceConnection::class))->execute();
    }

    public function testExecuteSendsReminderForExpiringOffer(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isOfferReminderEnabled')->willReturn(true);
        $config->method('getOfferLeadDays')->willReturn(2);
        $config->method('getOfferMaxSelfExtensions')->willReturn(1);

        $offer = $this->createMock(Offer::class);
        $offer->method('getEntityId')->willReturn(4);
        $offer->method('getCustomerId')->willReturn(5);
        $offer->method('getReference')->willReturn('OFR-1');
        $offer->method('getTotal')->willReturn(199.0);
        $offer->method('getCurrencyCode')->willReturn('PLN');
        $offer->method('getExpiresAt')->willReturn('2026-03-01');
        $offer->method('canSelfExtend')->willReturn(true);

        $collection = $this->createMock(Collection::class);
        $collection->method('addExpiringOnFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$offer]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 offer expiry reminders'));

        $this->makeCron($config, $collectionFactory, $resourceConnection, $logger)->execute();
    }

    public function testExecuteSkipsAlreadyRemindedOffer(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isOfferReminderEnabled')->willReturn(true);
        $config->method('getOfferLeadDays')->willReturn(2);

        $offer = $this->createMock(Offer::class);
        $offer->method('getEntityId')->willReturn(4);

        $collection = $this->createMock(Collection::class);
        $collection->method('addExpiringOnFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$offer]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->makeCron($config, $collectionFactory, $resourceConnection)->execute();
    }

    private function makeCron(
        Config $config,
        CollectionFactory $collectionFactory,
        ResourceConnection $resourceConnection,
        ?LoggerInterface $logger = null
    ): SendOfferExpiryReminders {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);

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

        $salesRepEmailContext = $this->createMock(SalesRepEmailContext::class);
        $salesRepEmailContext->method('getForCustomer')->willReturn([]);

        return new SendOfferExpiryReminders(
            $config,
            $collectionFactory,
            $resourceConnection,
            $customerRepository,
            $transportBuilder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $salesRepEmailContext,
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }
}
