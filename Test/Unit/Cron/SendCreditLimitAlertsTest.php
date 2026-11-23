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
use Ordo\Automation\Cron\SendCreditLimitAlerts;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\CreditLimitCalculator;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Email\PendingMessageIdHolder;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendCreditLimitAlertsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    /**
     * ReminderLogStore::claim() now wraps the count-check in GET_LOCK()/RELEASE_LOCK() - a raw
     * SQL string first arg to fetchOne(), unlike the count query's own Select object - so the
     * generic AdapterInterface mock these tests share needs to answer both distinctly instead of
     * one flat ->willReturn($n) for every fetchOne() call. $countResult is exactly what the old
     * flat stub used to mean: 0 = "not yet sent" (claim proceeds to insert), 1 = "already sent"
     * (claim refuses, no insert).
     */
    private function stubFetchOne(AdapterInterface $connection, int $countResult): void
    {
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $countResult
        );
        // Default: nothing pre-filtered by the batch "already alerted" check
        // (fetchExistingIdGroupPairs()) - a test whose whole point is exercising that check
        // overrides this via stubFetchAll() below instead of calling this helper.
        $connection->method('fetchAll')->willReturn([]);
    }

    /**
     * Another process claimed this exact customer/band in the window between the batch
     * pre-filter (fetchExistingIdGroupPairs()) running and claim() itself being reached -
     * claim()'s own re-check under the lock is the real guard, independent of that pre-filter.
     */
    private function stubFetchOneRaceLostInsideClaim(AdapterInterface $connection): void
    {
        // GET_LOCK() succeeds, and the real count query (inside claim()) finds the row already
        // claimed - both branches return a truthy 1, so a single flat stub covers both.
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchAll')->willReturn([]);
    }

    /**
     * For a test whose own point is the batch "already alerted" pre-filter
     * (fetchExistingIdGroupPairs()) itself, not claim()'s own re-check.
     *
     * @param array<int, array{customer_id: int, threshold_percent: int}> $alertedPairs
     */
    private function stubFetchAll(AdapterInterface $connection, array $alertedPairs): void
    {
        $connection->method('fetchAll')->willReturn($alertedPairs);
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

    private function makeReminderLogStore(ResourceConnection $resourceConnection): ReminderLogStore
    {
        return new ReminderLogStore($resourceConnection);
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

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(false);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->expects(self::never())->method('getCustomerIdsWithCreditLimit');

        $this->makeCron($config, $calculator, $this->createStub(ResourceConnection::class))->execute();
    }

    public function testExecuteLogsZeroSentWhenNoCustomersHaveACreditLimit(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);

        $calculator = $this->createStub(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 credit limit alerts'));

        $this->makeCron($config, $calculator, $this->createStub(ResourceConnection::class), $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCustomerBelowThreshold(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUsedCreditForCustomers')->willReturn([5 => 500.0]);
        $calculator->method('getCreditLimitFromCustomer')->willReturn(1000.0);

        // The batch "already alerted" pre-filter (fetchExistingIdGroupPairs()) still runs for
        // every customer with a credit limit regardless of their own band, same as the
        // consent/used-credit batches above it - this customer is simply below threshold
        // (band === null), so claim()/insert must never be reached either way.
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $this->stubFetchAll($connection, []);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->makeCron($config, $calculator, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsWarningBandAlert(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUsedCreditForCustomers')->willReturn([5 => 850.0]);
        $calculator->method('getCreditLimitFromCustomer')->willReturn(1000.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 credit limit alerts'));

        $this->makeCron($config, $calculator, $resourceConnection, $logger)->execute();
    }

    /**
     * Regression test for a real consent-bypass bug a code audit found: this cron used to have no
     * ConsentManager check at all.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCustomerWhoWithdrewEmailConsent(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUsedCreditForCustomers')->willReturn([5 => 850.0]);
        $calculator->method('getCreditLimitFromCustomer')->willReturn(1000.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::once())->method('hasTopicConsentForCustomers')->with([5], ConsentChannel::Email, ConsentTopic::AccountUpdates)->willReturn([5 => false]);

        (new SendCreditLimitAlerts(
            $config,
            $calculator,
            $customerMapBuilder,
            new ReminderEmailSender(
                $this->createStub(TransportBuilder::class),
                $this->createStub(StoreManagerInterface::class),
                $this->createStub(StateInterface::class)
            , new PendingMessageIdHolder()),
            $this->makeReminderLogStore($resourceConnection),
            $this->createStub(SalesRepEmailContext::class),
            $consentManager,
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $this->makeCustomerStoreResolver()
        ))->execute();
    }

    /**
     * Regression: the batch "already alerted" pre-filter (fetchExistingIdGroupPairs()) must skip
     * a customer/band pair it already found, before claim()/insert are ever reached.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenAlertedRecently(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUsedCreditForCustomers')->willReturn([5 => 1200.0]);
        $calculator->method('getCreditLimitFromCustomer')->willReturn(1000.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        // utilization 120% >= OVER_LIMIT_BAND (100), so this customer's own band is 100.
        $this->stubFetchAll($connection, [['customer_id' => 5, 'threshold_percent' => 100]]);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->makeCron($config, $calculator, $resourceConnection)->execute();
    }

    /**
     * Regression: claim() itself, not just the cheap alertedRecently() pre-check, must be the
     * real guard against a double-send - a race lost inside claim() (another process claimed
     * this customer/band between the pre-check and the lock being acquired) must still skip the
     * alert, not send it anyway.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenClaimLosesTheRaceEvenThoughThePreCheckPassed(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUsedCreditForCustomers')->willReturn([5 => 1200.0]);
        $calculator->method('getCreditLimitFromCustomer')->willReturn(1000.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $this->stubFetchOneRaceLostInsideClaim($connection);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->makeCron($config, $calculator, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSendingAlertThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUsedCreditForCustomers')->willReturn([5 => 850.0]);
        $calculator->method('getCreditLimitFromCustomer')->willReturn(1000.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $this->stubFetchOne($connection, 0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');
        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('send failed'));
        $salesRepEmailContext = $this->createStub(SalesRepEmailContext::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendCreditLimitAlerts(
            $config,
            $calculator,
            $customerMapBuilder,
            new ReminderEmailSender($transportBuilder, $storeManager, $this->createStub(StateInterface::class), new PendingMessageIdHolder()),
            $this->makeReminderLogStore($resourceConnection),
            $salesRepEmailContext,
            $this->makeConsentManager(),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($logger),
            $this->makeCustomerStoreResolver()
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCustomerMissingFromBatchLookup(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUsedCreditForCustomers')->willReturn([5 => 850.0]);
        $calculator->expects(self::never())->method('getCreditLimitFromCustomer');

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $this->stubFetchOne($connection, 0);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customerMapBuilder = $this->makeCustomerMapBuilder([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 credit limit alerts'));

        (new SendCreditLimitAlerts(
            $config,
            $calculator,
            $customerMapBuilder,
            new ReminderEmailSender(
                $this->createStub(TransportBuilder::class),
                $this->createStub(StoreManagerInterface::class),
                $this->createStub(StateInterface::class)
            , new PendingMessageIdHolder()),
            $this->makeReminderLogStore($resourceConnection),
            $this->createStub(SalesRepEmailContext::class),
            $this->makeConsentManager(),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($logger),
            $this->makeCustomerStoreResolver()
        ))->execute();
    }

    private function makeCron(
        Config $config,
        CreditLimitCalculator $calculator,
        ResourceConnection $resourceConnection,
        ?LoggerInterface $logger = null
    ): SendCreditLimitAlerts {
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

        $salesRepEmailContext = $this->createStub(SalesRepEmailContext::class);
        $salesRepEmailContext->method('getForCustomer')->willReturn([]);

        return new SendCreditLimitAlerts(
            $config,
            $calculator,
            $customerMapBuilder,
            new ReminderEmailSender($transportBuilder, $storeManager, $this->createStub(StateInterface::class), new PendingMessageIdHolder()),
            $this->makeReminderLogStore($resourceConnection),
            $salesRepEmailContext,
            $this->makeConsentManager(),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class)),
            $this->makeCustomerStoreResolver()
        );
    }
}
