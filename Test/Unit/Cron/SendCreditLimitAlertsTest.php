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
use Ordo\Automation\Model\CreditLimitCalculator;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendCreditLimitAlertsTest extends TestCase
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

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUtilizationPercent')->willReturnMap([[5, 50.0]]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

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
        $calculator->method('getUtilizationPercent')->willReturnMap([[5, 85.0]]);
        $calculator->method('getCreditLimit')->willReturn(1000.0);
        $calculator->method('getUsedCredit')->willReturn(850.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 credit limit alerts'));

        $this->makeCron($config, $calculator, $resourceConnection, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenAlertedRecently(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUtilizationPercent')->willReturn(120.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(1);
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
        $calculator->method('getUtilizationPercent')->willReturn(85.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');
        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([$customer]);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('send failed'));
        $salesRepEmailContext = $this->createStub(SalesRepEmailContext::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendCreditLimitAlerts(
            $config,
            $calculator,
            $resourceConnection,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $storeManager,
            $this->createStub(StateInterface::class),
            $salesRepEmailContext,
            $this->createStub(TriggerOutcomeLogger::class),
            $logger
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
        $calculator->method('getUtilizationPercent')->willReturn(85.0);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 credit limit alerts'));

        (new SendCreditLimitAlerts(
            $config,
            $calculator,
            $resourceConnection,
            $customerRepository,
            $searchCriteriaBuilder,
            $this->createStub(TransportBuilder::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
            $this->createStub(SalesRepEmailContext::class),
            $this->createStub(TriggerOutcomeLogger::class),
            $logger
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

        return new SendCreditLimitAlerts(
            $config,
            $calculator,
            $resourceConnection,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $storeManager,
            $this->createStub(StateInterface::class),
            $salesRepEmailContext,
            $this->createStub(TriggerOutcomeLogger::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
