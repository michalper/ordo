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
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class SendWinBackEmailsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

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

    private function makeEmailSender(TransportBuilder $transportBuilder): ReminderEmailSender
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new ReminderEmailSender($transportBuilder, $storeManager, $this->createStub(StateInterface::class));
    }

    private function makeConsentManager(bool $hasConsent = true): ConsentManager
    {
        $consentManager = $this->createStub(ConsentManager::class);
        $consentManager->method('hasConsentForCustomers')->willReturnCallback(
            fn (array $customerIds) => array_fill_keys($customerIds, $hasConsent)
        );

        return $consentManager;
    }

    private function makeWorkingTransportBuilder(): TransportBuilder
    {
        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));

        return $transportBuilder;
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('getCustomerIdsWithTag');

        $this->makeCron($config, $tagManager)->execute();
    }

    public function testExecuteLogsZeroSentWhenNoInactiveCustomers(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createStub(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([]);

        $customerMapBuilder = $this->makeCustomerMapBuilder([]);
        $emailSender = $this->makeEmailSender($this->createStub(TransportBuilder::class));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 win-back emails'));

        (new SendWinBackEmails(
            $config,
            $tagManager,
            $customerMapBuilder,
            $emailSender,
            $this->makeConsentManager(),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($logger)
        ))->execute();
    }

    public function testExecuteSkipsCustomerAlreadySent(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturnMap([[TagInactiveCustomers::TAG_INACTIVE, [5]]]);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturn([5]);
        $tagManager->expects(self::never())->method('addTag');

        $this->makeCron($config, $tagManager)->execute();
    }

    public function testExecuteSendsAndTagsCustomer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturn([]);
        $tagManager->expects(self::once())->method('addTag')->with(5, SendWinBackEmails::TAG_WIN_BACK_SENT);

        $this->makeCron($config, $tagManager)->execute();
    }

    /**
     * Regression test for a real consent-bypass bug a code audit found: this cron used to have no
     * ConsentManager check at all, so a customer who opted out of email via the GDPR admin screen
     * still received this marketing email.
     */
    public function testExecuteSkipsCustomerWhoWithdrewEmailConsent(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturn([]);
        $tagManager->expects(self::never())->method('addTag');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::once())->method('hasConsentForCustomers')
            ->with([5], ConsentChannel::Email)->willReturn([5 => false]);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::never())->method('setTemplateIdentifier');

        (new SendWinBackEmails(
            $config,
            $tagManager,
            $customerMapBuilder,
            $this->makeEmailSender($transportBuilder),
            $consentManager,
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenSendingThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturn([]);
        // Claimed (tagged) BEFORE the send attempt, then rolled back since the send fails - see
        // SendWinBackEmails' own "claim before sending" comment.
        $tagManager->expects(self::once())->method('addTag')->with(5, SendWinBackEmails::TAG_WIN_BACK_SENT);
        $tagManager->expects(self::once())->method('removeTag')->with(5, SendWinBackEmails::TAG_WIN_BACK_SENT);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);

        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('send failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendWinBackEmails(
            $config,
            $tagManager,
            $customerMapBuilder,
            $this->makeEmailSender($transportBuilder),
            $this->makeConsentManager(),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($logger)
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCustomerMissingFromBatchLookup(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturn([]);
        $tagManager->expects(self::never())->method('addTag');

        $customerMapBuilder = $this->makeCustomerMapBuilder([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        (new SendWinBackEmails(
            $config,
            $tagManager,
            $customerMapBuilder,
            $this->makeEmailSender($this->createStub(TransportBuilder::class)),
            $this->makeConsentManager(),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($logger)
        ))->execute();
    }

    private function makeCron(Config $config, CustomerTagManager $tagManager): SendWinBackEmails
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        $customerMapBuilder = $this->makeCustomerMapBuilder([$customer]);
        $emailSender = $this->makeEmailSender($this->makeWorkingTransportBuilder());

        return new SendWinBackEmails(
            $config,
            $tagManager,
            $customerMapBuilder,
            $emailSender,
            $this->makeConsentManager(),
            $this->createStub(TriggerOutcomeLogger::class),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        );
    }
}
