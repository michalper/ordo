<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Sms;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Sms\CallbackUrlBuilder;
use PHPUnit\Framework\TestCase;

class CallbackUrlBuilderTest extends TestCase
{
    private function makeBuilder(string $baseUrl): CallbackUrlBuilder
    {
        $store = $this->createMock(Store::class);
        $store->expects(self::once())->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB, true)->willReturn($baseUrl);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CallbackUrlBuilder($storeManager);
    }

    public function testAppendsCallbackPathToBaseUrl(): void
    {
        $builder = $this->makeBuilder('https://example.com');

        self::assertSame(
            'https://example.com/ordo/sms/statuscallback',
            $builder->getSmsStatusCallbackUrl()
        );
    }

    public function testStripsTrailingSlashFromBaseUrl(): void
    {
        $builder = $this->makeBuilder('https://example.com/');

        self::assertSame(
            'https://example.com/ordo/sms/statuscallback',
            $builder->getSmsStatusCallbackUrl()
        );
    }
}
