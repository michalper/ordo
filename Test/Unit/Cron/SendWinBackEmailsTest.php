<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendWinBackEmails;
use Ordo\Automation\Cron\TagInactiveCustomers;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendWinBackEmailsTest extends TestCase
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

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('getCustomerIdsWithTag');

        $this->makeCron($config, $tagManager)->execute();
    }

    public function testExecuteSkipsCustomerAlreadySent(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->with(TagInactiveCustomers::TAG_INACTIVE)->willReturn([5]);
        $tagManager->method('hasTag')->with(5, SendWinBackEmails::TAG_WIN_BACK_SENT)->willReturn(true);
        $tagManager->expects(self::never())->method('addTag');

        $this->makeCron($config, $tagManager)->execute();
    }

    public function testExecuteSendsAndTagsCustomer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('hasTag')->willReturn(false);
        $tagManager->expects(self::once())->method('addTag')->with(5, SendWinBackEmails::TAG_WIN_BACK_SENT);

        $this->makeCron($config, $tagManager)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSendingThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('hasTag')->willReturn(false);
        $tagManager->expects(self::never())->method('addTag');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([$customer]);

        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('send failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendWinBackEmails(
            $config,
            $tagManager,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
            $this->createStub(TriggerOutcomeLogger::class),
            $logger
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCustomerMissingFromBatchLookup(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('hasTag')->willReturn(false);
        $tagManager->expects(self::never())->method('addTag');

        [$customerRepository, $searchCriteriaBuilder] = $this->makeCustomerRepository([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        (new SendWinBackEmails(
            $config,
            $tagManager,
            $customerRepository,
            $searchCriteriaBuilder,
            $this->createStub(TransportBuilder::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(StateInterface::class),
            $this->createStub(TriggerOutcomeLogger::class),
            $logger
        ))->execute();
    }

    private function makeCron(Config $config, CustomerTagManager $tagManager): SendWinBackEmails
    {
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

        return new SendWinBackEmails(
            $config,
            $tagManager,
            $customerRepository,
            $searchCriteriaBuilder,
            $transportBuilder,
            $storeManager,
            $this->createStub(StateInterface::class),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->createStub(LoggerInterface::class)
        );
    }
}
