<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\LoyaltyWalletPass;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Controller\LoyaltyWalletPass\Google;
use Ordo\Automation\Model\Wallet\GoogleWalletPassLinkBuilder;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class GoogleTest extends AbstractFrontendActionTestCase
{
    private CustomerSession $customerSession;
    private CustomerUrl $customerUrl;
    private GoogleWalletPassLinkBuilder $googleWalletPassLinkBuilder;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->customerUrl = $this->createStub(CustomerUrl::class);
        $this->googleWalletPassLinkBuilder = $this->createMock(GoogleWalletPassLinkBuilder::class);
    }

    private function makeController(): Google
    {
        return new Google(
            $this->makeContext(),
            $this->customerSession,
            $this->customerUrl,
            $this->googleWalletPassLinkBuilder
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheRealGoogleSaveUrl(): void
    {
        $controller = $this->makeController();
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $this->googleWalletPassLinkBuilder->expects(self::once())->method('buildSaveUrl')->with(42)
            ->willReturn('https://pay.google.com/gp/v/save/abc123');

        $capturedUrl = null;
        $this->resultRedirect->method('setUrl')->willReturnCallback(
            function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return $this->resultRedirect;
            }
        );

        self::assertSame($this->resultRedirect, $controller->execute());
        self::assertSame('https://pay.google.com/gp/v/save/abc123', $capturedUrl);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorAndRedirectsToMyOffersWhenNotConfigured(): void
    {
        $controller = $this->makeController();
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $exception = new LocalizedException(__('Google Wallet is not fully configured.'));
        $this->googleWalletPassLinkBuilder->method('buildSaveUrl')->willThrowException($exception);

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with($exception->getMessage());

        $capturedPath = null;
        $this->resultRedirect->method('setPath')->willReturnCallback(
            function (string $path) use (&$capturedPath) {
                $capturedPath = $path;
                return $this->resultRedirect;
            }
        );

        self::assertSame($this->resultRedirect, $controller->execute());
        self::assertSame('ordo/offer/index', $capturedPath);
    }
}
