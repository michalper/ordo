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
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendSalesRepDigestTest extends TestCase
{
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

    public function testExecuteSkipsWhenDigestDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('getCustomerIdsWithTag');

        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $searchCriteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $transportBuilder = $this->createStub(TransportBuilder::class);
        $logger = $this->createStub(LoggerInterface::class);

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $storeManager,
            $this->createStub(StateInterface::class),
            $logger
        ))->execute();
    }

    public function testExecuteGroupsByRepAndSendsOneDigest(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->with(TagInactiveCustomers::TAG_INACTIVE)->willReturn([5, 6]);

        $repAttr = $this->createStub(AttributeInterface::class);
        $repAttr->method('getValue')->willReturn('rep@example.com');

        $customer5 = $this->createMock(CustomerInterface::class);
        $customer5->method('getCustomAttribute')->with(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)->willReturn($repAttr);
        $customer5->method('getFirstname')->willReturn('Jan');
        $customer5->method('getLastname')->willReturn('Kowalski');

        $customer6 = $this->createMock(CustomerInterface::class);
        $customer6->method('getCustomAttribute')->with(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)->willReturn(null);
        $customer6->method('getId')->willReturn(6);

        $customer5->method('getId')->willReturn(5);

        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([$customer5, $customer6]);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('addTo')->with('rep@example.com')->willReturnSelf();

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');
        $transportBuilder->method('getTransport')->willReturn($transport);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 sales rep digests'));

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $storeManager,
            $this->createStub(StateInterface::class),
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

        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([]);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::never())->method('getTransport');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 sales rep digests'));

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
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

        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([$customer]);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerRepository,
            $searchCriteriaBuilder,
            $this->createStub(TransportBuilder::class),
            $storeManager,
            $this->createStub(StateInterface::class),
            $logger
        ))->execute();
    }
}
