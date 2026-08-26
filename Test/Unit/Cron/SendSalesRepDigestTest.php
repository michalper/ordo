<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
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

class SendSalesRepDigestTest extends TestCase
{
    public function testExecuteSkipsWhenDigestDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('getCustomerIdsWithTag');

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $transportBuilder = $this->createMock(TransportBuilder::class);
        $logger = $this->createMock(LoggerInterface::class);

        (new SendSalesRepDigest(
            $config,
            $tagManager,
            $customerRepository,
            $transportBuilder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $logger
        ))->execute();
    }

    public function testExecuteGroupsByRepAndSendsOneDigest(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isSalesRepDigestEnabled')->willReturn(true);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->with(TagInactiveCustomers::TAG_INACTIVE)->willReturn([5, 6]);

        $repAttr = $this->createMock(AttributeInterface::class);
        $repAttr->method('getValue')->willReturn('rep@example.com');

        $customer5 = $this->createMock(CustomerInterface::class);
        $customer5->method('getCustomAttribute')->with(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)->willReturn($repAttr);
        $customer5->method('getFirstname')->willReturn('Jan');
        $customer5->method('getLastname')->willReturn('Kowalski');

        $customer6 = $this->createMock(CustomerInterface::class);
        $customer6->method('getCustomAttribute')->with(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)->willReturn(null);

        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturnMap([
            [5, $customer5],
            [6, $customer6],
        ]);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
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
            $transportBuilder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $logger
        ))->execute();
    }
}
