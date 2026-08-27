<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\SendWinBackEmails;
use Ordo\Automation\Cron\TagInactiveCustomers;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class SendWinBackEmailsTest extends TestCase
{
    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('getCustomerIdsWithTag');

        $this->makeCron($config, $tagManager)->execute();
    }

    public function testExecuteSkipsCustomerAlreadySent(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->with(TagInactiveCustomers::TAG_INACTIVE)->willReturn([5]);
        $tagManager->method('hasTag')->with(5, SendWinBackEmails::TAG_WIN_BACK_SENT)->willReturn(true);
        $tagManager->expects(self::never())->method('addTag');

        $this->makeCron($config, $tagManager)->execute();
    }

    public function testExecuteSendsAndTagsCustomer(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('hasTag')->willReturn(false);
        $tagManager->expects(self::once())->method('addTag')->with(5, SendWinBackEmails::TAG_WIN_BACK_SENT);

        $this->makeCron($config, $tagManager)->execute();
    }

    public function testExecuteLogsErrorWhenSendingThrows(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([5]);
        $tagManager->method('hasTag')->willReturn(false);
        $tagManager->expects(self::never())->method('addTag');

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willThrowException(new \RuntimeException('lookup failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new SendWinBackEmails(
            $config,
            $tagManager,
            $customerRepository,
            $this->createMock(TransportBuilder::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateInterface::class),
            $logger
        ))->execute();
    }

    private function makeCron(Config $config, CustomerTagManager $tagManager): SendWinBackEmails
    {
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

        return new SendWinBackEmails(
            $config,
            $tagManager,
            $customerRepository,
            $transportBuilder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
