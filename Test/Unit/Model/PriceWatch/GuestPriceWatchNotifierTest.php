<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\PriceWatch;

use Magento\Catalog\Model\Product;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\PriceWatch\GuestPriceWatchNotifier;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class GuestPriceWatchNotifierTest extends TestCase
{
    private function makeNotifier(TransportBuilder $transportBuilder): GuestPriceWatchNotifier
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $reminderEmailSender = new ReminderEmailSender(
            $transportBuilder,
            $storeManager,
            $this->createStub(StateInterface::class)
        );

        return new GuestPriceWatchNotifier($reminderEmailSender);
    }

    private function makeProduct(): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('Widget');
        $product->method('getProductUrl')->willReturn('https://shop.example.com/widget.html');

        return $product;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotifySendsPriceDropMessageToGuestAddress(): void
    {
        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::once())->method('setTemplateIdentifier')
            ->with('ordo_price_watch_guest_alert')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setTemplateVars')->with(self::callback(
            function (array $vars): bool {
                self::assertSame('Widget', $vars['product_name']);
                self::assertSame('https://shop.example.com/widget.html', $vars['product_url']);
                self::assertStringContainsString('80', $vars['message']);
                return true;
            }
        ))->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('addTo')->with('guest@example.com')->willReturnSelf();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');
        $transportBuilder->method('getTransport')->willReturn($transport);

        $this->makeNotifier($transportBuilder)->notify(
            'guest@example.com',
            $this->makeProduct(),
            PriceWatchSubscription::WATCH_TYPE_PRICE_DROP,
            ['old_price' => 100.0, 'new_price' => 80.0]
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotifySendsBackInStockMessage(): void
    {
        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->expects(self::once())->method('setTemplateVars')->with(self::callback(
            function (array $vars): bool {
                self::assertStringContainsString('back in stock', $vars['message']);
                return true;
            }
        ))->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');
        $transportBuilder->method('getTransport')->willReturn($transport);

        $this->makeNotifier($transportBuilder)->notify(
            'guest@example.com',
            $this->makeProduct(),
            PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK,
            ['old_in_stock' => false, 'new_in_stock' => true]
        );
    }
}
