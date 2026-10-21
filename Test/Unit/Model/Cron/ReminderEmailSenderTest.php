<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Cron;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Email\PendingMessageIdHolder;
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

        $sender = new ReminderEmailSender($transportBuilder, $storeManager, $inlineTranslation, new PendingMessageIdHolder());

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

        $sender = new ReminderEmailSender($transportBuilder, $storeManager, $this->createStub(StateInterface::class), new PendingMessageIdHolder());

        $sender->send('ordo_digest', [], 'rep@example.com');
    }

    public function testSendUsesThePassedStoreForScopeSenderAndTemplateVarInsteadOfTheCurrentOne(): void
    {
        $orderStore = $this->createStub(Store::class);
        $orderStore->method('getId')->willReturn(5);

        // The whole point of the override: the "current" store must not even be consulted.
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::once())->method('setTemplateIdentifier')
            ->with('ordo_order_approval_request')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setTemplateOptions')
            ->with(['area' => 'frontend', 'store' => 5])->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setTemplateVars')
            ->with(['order_increment_id' => '000000007', 'store' => $orderStore])->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setFromByScope')
            ->with('general', 5)->willReturnSelf();
        $transportBuilder->expects(self::once())->method('addTo')
            ->with('admin@example.com', '')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($transport);

        $sender = new ReminderEmailSender($transportBuilder, $storeManager, $this->createStub(StateInterface::class), new PendingMessageIdHolder());

        $sender->send(
            'ordo_order_approval_request',
            ['order_increment_id' => '000000007'],
            'admin@example.com',
            store: $orderStore
        );
    }

    public function testGetStoreReturnsCurrentStore(): void
    {
        $store = $this->createStub(Store::class);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $sender = new ReminderEmailSender(
            $this->createStub(TransportBuilder::class),
            $storeManager,
            $this->createStub(StateInterface::class),
            new PendingMessageIdHolder()
        );

        self::assertSame($store, $sender->getStore());
    }

    /**
     * The optional Message-ID path: the id is held for Plugin\Email\EmailMessageMessageIdPlugin
     * exactly for the duration of the send, then consumed — even when the transport throws, so
     * a failed send can never leak its id onto the next unrelated email.
     */
    public function testSendWithMessageIdHoldsItDuringTheSendAndConsumesItOnFailureToo(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $holder = new PendingMessageIdHolder();

        $transportBuilder = $this->createStub(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();

        $transport = $this->createStub(\Magento\Framework\Mail\TransportInterface::class);
        $heldDuringSend = null;
        $transport->method('sendMessage')->willReturnCallback(
            function () use ($holder, &$heldDuringSend): void {
                $heldDuringSend = $holder->consume();
                $holder->set((string) $heldDuringSend);
            }
        );
        $transportBuilder->method('getTransport')->willReturn($transport);

        $inlineTranslation = $this->createMock(StateInterface::class);
        $inlineTranslation->expects(self::once())->method('resume');

        $sender = new ReminderEmailSender($transportBuilder, $storeManager, $inlineTranslation, $holder);
        $sender->send('ordo_abandoned_cart_reminder', [], 'jan@example.com', messageId: 'ordo.abc@shop.test');

        self::assertSame('ordo.abc@shop.test', $heldDuringSend);
        self::assertNull($holder->consume(), 'the id must be consumed after the send');

        // Failure path: a throwing transport still consumes the held id.
        $failingTransport = $this->createStub(\Magento\Framework\Mail\TransportInterface::class);
        $failingTransport->method('sendMessage')->willThrowException(new \RuntimeException('smtp down'));
        $failingBuilder = $this->createStub(TransportBuilder::class);
        $failingBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $failingBuilder->method('setTemplateOptions')->willReturnSelf();
        $failingBuilder->method('setTemplateVars')->willReturnSelf();
        $failingBuilder->method('setFromByScope')->willReturnSelf();
        $failingBuilder->method('addTo')->willReturnSelf();
        $failingBuilder->method('getTransport')->willReturn($failingTransport);

        $failingSender = new ReminderEmailSender(
            $failingBuilder,
            $storeManager,
            $this->createStub(StateInterface::class),
            $holder
        );

        try {
            $failingSender->send('ordo_abandoned_cart_reminder', [], 'jan@example.com', messageId: 'ordo.x@shop.test');
            self::fail('expected the transport failure to propagate');
        } catch (\RuntimeException) {
            self::assertNull($holder->consume(), 'a failed send must not leak its id');
        }
    }
}
