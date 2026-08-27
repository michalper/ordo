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
use Ordo\Automation\Cron\SendCreditLimitAlerts;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CreditLimitCalculator;
use Ordo\Automation\Model\SalesRepEmailContext;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class SendCreditLimitAlertsTest extends TestCase
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
        $config->method('isCreditLimitAlertEnabled')->willReturn(false);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->expects(self::never())->method('getCustomerIdsWithCreditLimit');

        $this->makeCron($config, $calculator, $this->createMock(ResourceConnection::class))->execute();
    }

    public function testExecuteSkipsCustomerBelowThreshold(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUtilizationPercent')->with(5)->willReturn(50.0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $calculator, $resourceConnection)->execute();
    }

    public function testExecuteSendsWarningBandAlert(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isCreditLimitAlertEnabled')->willReturn(true);
        $config->method('getCreditLimitWarningThreshold')->willReturn(80);
        $config->method('getCreditLimitAlertCooldownDays')->willReturn(7);

        $calculator = $this->createMock(CreditLimitCalculator::class);
        $calculator->method('getCustomerIdsWithCreditLimit')->willReturn([5]);
        $calculator->method('getUtilizationPercent')->with(5)->willReturn(85.0);
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

    public function testExecuteSkipsWhenAlertedRecently(): void
    {
        $config = $this->createMock(Config::class);
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

    public function testExecuteLogsErrorWhenSendingAlertThrows(): void
    {
        $config = $this->createMock(Config::class);
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

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willThrowException(new \RuntimeException('customer lookup failed'));

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $transportBuilder = $this->createMock(TransportBuilder::class);
        $salesRepEmailContext = $this->createMock(SalesRepEmailContext::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendCreditLimitAlerts(
            $config,
            $calculator,
            $resourceConnection,
            $customerRepository,
            $transportBuilder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $salesRepEmailContext,
            $logger
        ))->execute();
    }

    private function makeCron(
        Config $config,
        CreditLimitCalculator $calculator,
        ResourceConnection $resourceConnection,
        ?LoggerInterface $logger = null
    ): SendCreditLimitAlerts {
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

        return new SendCreditLimitAlerts(
            $config,
            $calculator,
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
