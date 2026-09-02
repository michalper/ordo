<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendReorderReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\Collection;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory;
use Ordo\Automation\Model\SalesRepEmailContext;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendReorderRemindersTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    /**
     * @param CustomerInterface[] $customers
     * @return array{0: CustomerRepositoryInterface, 1: SearchCriteriaBuilder}
     */
    private function makeCustomerRepository(array $customers): array
    {
        $searchCriteria = $this->createStub(SearchCriteria::class);
        $searchCriteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $searchResults = $this->createStub(CustomerSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn($customers);

        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $customerRepository->method('getList')->willReturn($searchResults);

        return [$customerRepository, $searchCriteriaBuilder];
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReorderReminderEnabled')->willReturn(false);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->expects(self::never())->method('create');

        $this->makeCron($config, $collectionFactory, $this->createStub(ResourceConnection::class))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsReminderAndLogsIt(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReorderReminderEnabled')->willReturn(true);
        $config->method('getReorderLeadDays')->willReturn(2);

        $cycle = $this->createStub(ReorderCycle::class);
        $cycle->method('getEntityId')->willReturn(3);
        $cycle->method('getCustomerId')->willReturn(5);
        $cycle->method('getSku')->willReturn('SKU-1');
        $cycle->method('getAvgIntervalDays')->willReturn(30);

        $collection = $this->createStub(Collection::class);
        $collection->method('addDueTodayFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$cycle]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 reorder reminders'));

        $this->makeCron($config, $collectionFactory, $resourceConnection, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCycleAlreadyRemindedToday(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReorderReminderEnabled')->willReturn(true);
        $config->method('getReorderLeadDays')->willReturn(2);

        $cycle = $this->createStub(ReorderCycle::class);
        $cycle->method('getEntityId')->willReturn(3);

        $collection = $this->createStub(Collection::class);
        $collection->method('addDueTodayFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$cycle]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->makeCron($config, $collectionFactory, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSendingReminderThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReorderReminderEnabled')->willReturn(true);
        $config->method('getReorderLeadDays')->willReturn(2);

        $cycle = $this->createStub(ReorderCycle::class);
        $cycle->method('getEntityId')->willReturn(3);
        $cycle->method('getCustomerId')->willReturn(5);

        $collection = $this->createStub(Collection::class);
        $collection->method('addDueTodayFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$cycle]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([$customer]);

        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('send failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendReorderReminders(
            $config,
            $collectionFactory,
            $resourceConnection,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
            $this->createStub(SalesRepEmailContext::class),
            $logger
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCycleWhenCustomerMissingFromBatchLookup(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReorderReminderEnabled')->willReturn(true);
        $config->method('getReorderLeadDays')->willReturn(2);

        $cycle = $this->createStub(ReorderCycle::class);
        $cycle->method('getEntityId')->willReturn(3);
        $cycle->method('getCustomerId')->willReturn(5);

        $collection = $this->createStub(Collection::class);
        $collection->method('addDueTodayFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$cycle]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 reorder reminders'));

        (new SendReorderReminders(
            $config,
            $collectionFactory,
            $resourceConnection,
            $customerRepository,
            $searchCriteriaBuilder,
            $this->createStub(TransportBuilder::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
            $this->createStub(SalesRepEmailContext::class),
            $logger
        ))->execute();
    }

    private function makeCron(
        Config $config,
        CollectionFactory $collectionFactory,
        ResourceConnection $resourceConnection,
        ?LoggerInterface $logger = null
    ): SendReorderReminders {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([$customer]);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));

        $salesRepEmailContext = $this->createStub(SalesRepEmailContext::class);
        $salesRepEmailContext->method('getForCustomer')->willReturn([]);

        return new SendReorderReminders(
            $config,
            $collectionFactory,
            $resourceConnection,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $storeManager,
            $this->createStub(StateInterface::class),
            $salesRepEmailContext,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
