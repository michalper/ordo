<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Observer\SendWelcomeEmail;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class SendWelcomeEmailTest extends TestCase
{
    public function testExecuteSkipsWhenLifecycleEmailsDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(false);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('addTag');

        $observer = $this->createMock(EventObserver::class);

        $this->makeObserver($config, $tagManager)->execute($observer);
    }

    public function testExecuteTagsAndSendsEmail(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');

        $event = new Event(['customer' => $customer]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::once())->method('addTag')->with(42, SendWelcomeEmail::TAG_NEW_CUSTOMER);

        $this->makeObserver($config, $tagManager)->execute($observer);
    }

    public function testExecuteDoesNothingWhenCustomerMissing(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLifecycleEmailsEnabled')->willReturn(true);

        $event = new Event([]);

        $observer = $this->createMock(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('addTag');

        $this->makeObserver($config, $tagManager)->execute($observer);
    }

    private function makeObserver(Config $config, CustomerTagManager $tagManager): SendWelcomeEmail
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();

        $transport = $this->createMock(TransportInterface::class);
        $transportBuilder->method('getTransport')->willReturn($transport);

        return new SendWelcomeEmail(
            $config,
            $tagManager,
            $transportBuilder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
