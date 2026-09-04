<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Cron;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use PHPUnit\Framework\TestCase;

class ReminderEmailSenderTest extends TestCase
{
    public function testSendBuildsTransportWithTemplateVarsAndStoreThenSendsAndResumesTranslation(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $inlineTranslation = $this->createMock(StateInterface::class);
        $inlineTranslation->expects(self::once())->method('suspend');
        $inlineTranslation->expects(self::once())->method('resume');

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::once())->method('setTemplateIdentifier')
            ->with('ordo_some_template')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setTemplateOptions')
            ->with(['area' => 'frontend', 'store' => 1])->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setTemplateVars')
            ->with(['customer_name' => 'Jan', 'store' => $store])->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setFromByScope')
            ->with('general', 1)->willReturnSelf();
        $transportBuilder->expects(self::once())->method('addTo')
            ->with('jan@example.com', 'Jan')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($transport);

        $sender = new ReminderEmailSender($transportBuilder, $storeManager, $inlineTranslation);

        $sender->send('ordo_some_template', ['customer_name' => 'Jan'], 'jan@example.com', 'Jan');
    }

    public function testSendDefaultsToEmptyRecipientName(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $transport = $this->createStub(TransportInterface::class);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('addTo')->with('rep@example.com', '')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($transport);

        $sender = new ReminderEmailSender($transportBuilder, $storeManager, $this->createStub(StateInterface::class));

        $sender->send('ordo_digest', [], 'rep@example.com');
    }

    public function testGetStoreReturnsCurrentStore(): void
    {
        $store = $this->createStub(Store::class);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $sender = new ReminderEmailSender(
            $this->createStub(TransportBuilder::class),
            $storeManager,
            $this->createStub(StateInterface::class)
        );

        self::assertSame($store, $sender->getStore());
    }
}
