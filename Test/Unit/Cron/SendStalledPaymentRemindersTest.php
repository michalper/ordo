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
use Ordo\Automation\Cron\SendStalledPaymentReminders;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Email\PendingMessageIdHolder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendStalledPaymentRemindersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();

        return $select;
    }

    /**
     * Same reasoning as SendOfferExpiryRemindersTest's identical helper: claim() wraps the
     * count-check in GET_LOCK()/RELEASE_LOCK() - a raw SQL string first arg to fetchOne(),
     * unlike the count query's own Select object - so the shared AdapterInterface mock needs to
     * answer both distinctly.
     */
    private function stubFetchOne(AdapterInterface $connection, int $countResult): void
    {
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $countResult
        );
    }

    /**
     * @param CustomerInterface[] $customers
     */
    private function makeCustomerMapBuilder(array $customers): CustomerMapBuilder
    {
        $searchCriteria = $this->createStub(SearchCriteria::class);
        $searchCriteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $searchResults = $this->createStub(CustomerSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn($customers);

        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $customerRepository->method('getList')->willReturn($searchResults);

        return new CustomerMapBuilder($customerRepository, $searchCriteriaBuilder);
    }

    private function makeConsentManager(bool $hasConsent = true): ConsentManager
    {
        $consentManager = $this->createStub(ConsentManager::class);
        $consentManager->method('hasTopicConsentForCustomers')->willReturnCallback(
            fn (array $customerIds) => array_fill_keys($customerIds, $hasConsent)
        );

        return $consentManager;
    }

    private function makeCustomerStoreResolver(): CustomerStoreResolver
    {
        $resolver = $this->createStub(CustomerStoreResolver::class);
        $resolver->method('resolveStoreId')->willReturn(null);

        return $resolver;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isStalledPaymentReminderEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsReminderForAStalledOrder(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isStalledPaymentReminderEnabled')->willReturn(true);
        $config->method('getStalledPaymentLeadHours')->willReturn(24);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['order_id' => 10, 'customer_id' => 5, 'increment_id' => '000000010'],
        ]);
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 stalled payment reminder'));

        $this->makeCron($config, $resourceConnection, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsZeroSentWhenNoOrdersAreStalled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isStalledPaymentReminderEnabled')->willReturn(true);
        $config->method('getStalledPaymentLeadHours')->willReturn(24);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 stalled payment reminder(s)'));

        $this->makeCron($config, $resourceConnection, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsAlreadyRemindedOrder(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isStalledPaymentReminderEnabled')->willReturn(true);
        $config->method('getStalledPaymentLeadHours')->willReturn(24);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['order_id' => 10, 'customer_id' => 5, 'increment_id' => '000000010'],
        ]);
        $this->stubFetchOne($connection, 1);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->makeCron($config, $resourceConnection)->execute();
    }

    /**
     * Regression-shaped test, same reasoning as SendOfferExpiryReminders' identical one: a
     * consent check must actually gate the send, not just exist unreachably.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsOrderWhenCustomerWithdrewEmailConsent(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isStalledPaymentReminderEnabled')->willReturn(true);
        $config->method('getStalledPaymentLeadHours')->willReturn(24);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['order_id' => 10, 'customer_id' => 5, 'increment_id' => '000000010'],
        ]);
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::once())->method('hasTopicConsentForCustomers')
            ->with([5], ConsentChannel::Email, ConsentTopic::AccountUpdates)
            ->willReturn([5 => false]);

        (new SendStalledPaymentReminders(
            $config,
            $resourceConnection,
            $customerMapBuilder,
            new ReminderEmailSender(
                $this->createStub(TransportBuilder::class),
                $this->createStub(StoreManagerInterface::class),
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            new ReminderLogStore($resourceConnection),
            $consentManager,
            $this->makeCustomerStoreResolver(),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSendingReminderThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isStalledPaymentReminderEnabled')->willReturn(true);
        $config->method('getStalledPaymentLeadHours')->willReturn(24);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['order_id' => 10, 'customer_id' => 5, 'increment_id' => '000000010'],
        ]);
        $this->stubFetchOne($connection, 0);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('send failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendStalledPaymentReminders(
            $config,
            $resourceConnection,
            $customerMapBuilder,
            new ReminderEmailSender(
                $transportBuilder,
                $this->createStub(StoreManagerInterface::class),
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            new ReminderLogStore($resourceConnection),
            $this->makeConsentManager(),
            $this->makeCustomerStoreResolver(),
            $this->makeCronRunLogger($logger)
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsOrderWhenCustomerMissingFromBatchLookup(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isStalledPaymentReminderEnabled')->willReturn(true);
        $config->method('getStalledPaymentLeadHours')->willReturn(24);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['order_id' => 10, 'customer_id' => 5, 'increment_id' => '000000010'],
        ]);
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customerMapBuilder = $this->makeCustomerMapBuilder([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 stalled payment reminder(s)'));

        (new SendStalledPaymentReminders(
            $config,
            $resourceConnection,
            $customerMapBuilder,
            new ReminderEmailSender(
                $this->createStub(TransportBuilder::class),
                $this->createStub(StoreManagerInterface::class),
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            new ReminderLogStore($resourceConnection),
            $this->makeConsentManager(),
            $this->makeCustomerStoreResolver(),
            $this->makeCronRunLogger($logger)
        ))->execute();
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        ?LoggerInterface $logger = null
    ): SendStalledPaymentReminders {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

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

        return new SendStalledPaymentReminders(
            $config,
            $resourceConnection,
            $customerMapBuilder,
            new ReminderEmailSender(
                $transportBuilder,
                $storeManager,
                $this->createStub(StateInterface::class),
                new PendingMessageIdHolder()
            ),
            new ReminderLogStore($resourceConnection),
            $this->makeConsentManager(),
            $this->makeCustomerStoreResolver(),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class))
        );
    }
}
