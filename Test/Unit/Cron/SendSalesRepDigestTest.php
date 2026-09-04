<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendSalesRepDigest;
use Ordo\Automation\Cron\TagInactiveCustomers;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendSalesRepDigestTest extends TestCase
{
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

    private function makeEmailSender(TransportBuilder $transportBuilder, StoreManagerInterface $storeManager): ReminderEmailSender
    {
        return new ReminderEmailSender($transportBuilder, $storeManager, $this->createStub(StateInterface::class));
    }

    public function testExecuteSkipsWhenDigestDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('getCustomerIdsWithTag');

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $this->makeCustomerMapBuilder([]),
            $this->makeEmailSender(
                $this->createStub(TransportBuilder::class),
                $this->createStub(StoreManagerInterface::class)
            ),
            $this->createStub(LoggerInterface::class)
        ))->execute();
    }

    public function testExecuteLogsZeroSentWhenNoInactiveCustomers(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(true);

        $tagManager = $this->createStub(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 sales rep digests'));

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $this->makeCustomerMapBuilder([]),
            $this->makeEmailSender(
                $this->createStub(TransportBuilder::class),
                $this->createStub(StoreManagerInterface::class)
            ),
            $logger
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteGroupsByRepAndSendsOneDigest(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')
            ->willReturnMap([[TagInactiveCustomers::TAG_INACTIVE, [5, 6]]]);

        $repAttr = $this->createStub(AttributeInterface::class);
        $repAttr->method('getValue')->willReturn('rep@example.com');

        $customer5 = $this->createMock(CustomerInterface::class);
        $customer5->method('getCustomAttribute')
            ->willReturnMap([[AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL, $repAttr]]);
        $customer5->method('getFirstname')->willReturn('Jan');
        $customer5->method('getLastname')->willReturn('Kowalski');

        $customer6 = $this->createMock(CustomerInterface::class);
        $customer6->method('getCustomAttribute')
            ->willReturnMap([[AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL, null]]);
        $customer6->method('getId')->willReturn(6);

        $customer5->method('getId')->willReturn(5);

        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer5, $customer6]);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('addTo')->with('rep@example.com', '')->willReturnSelf();

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');
        $transportBuilder->method('getTransport')->willReturn($transport);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 sales rep digests'));

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerMapBuilder,
            $this->makeEmailSender($transportBuilder, $storeManager),
            $logger
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCustomerMissingFromBatchLookup(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);

        $customerMapBuilder = $this->makeCustomerMapBuilder([]);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::never())->method('getTransport');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 sales rep digests'));

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerMapBuilder,
            $this->makeEmailSender($transportBuilder, $this->createStub(StoreManagerInterface::class)),
            $logger
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSendingDigestThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);

        $repAttr = $this->createStub(AttributeInterface::class);
        $repAttr->method('getValue')->willReturn('rep@example.com');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn($repAttr);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getLastname')->willReturn('Kowalski');
        $customer->method('getId')->willReturn(5);

        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerMapBuilder,
            $this->makeEmailSender($this->createStub(TransportBuilder::class), $storeManager),
            $logger
        ))->execute();
    }
}
