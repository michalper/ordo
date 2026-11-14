<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Email;

use Magento\Store\Model\Store;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\Email\UnsubscribeContext;
use Ordo\Automation\Model\Email\UnsubscribeTokenManager;
use PHPUnit\Framework\TestCase;

class UnsubscribeContextTest extends TestCase
{
    public function testGetForCustomerBuildsAUrlFromTheStoresBaseUrlAndTheToken(): void
    {
        $tokenManager = $this->createMock(UnsubscribeTokenManager::class);
        $tokenManager->expects(self::once())->method('generate')
            ->with(42, ConsentChannel::Email)
            ->willReturn('42.email.signature');

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        $context = new UnsubscribeContext($tokenManager);

        self::assertSame(
            ['unsubscribe_url' => 'https://example.com/ordo/email/unsubscribe/token/42.email.signature'],
            $context->getForCustomer(42, $store)
        );
    }

    public function testGetForCustomerStripsATrailingSlashFromTheBaseUrl(): void
    {
        $tokenManager = $this->createStub(UnsubscribeTokenManager::class);
        $tokenManager->method('generate')->willReturn('42.email.signature');

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com');

        $context = new UnsubscribeContext($tokenManager);

        self::assertSame(
            'https://example.com/ordo/email/unsubscribe/token/42.email.signature',
            $context->getForCustomer(42, $store)['unsubscribe_url']
        );
    }
}
