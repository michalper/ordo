<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Consent;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Controller\Consent\SetChannel;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SetChannelTest extends AbstractFrontendActionTestCase
{
    private CustomerSession $customerSession;
    private CustomerUrl $customerUrl;
    private ConsentPreferencesManagementInterface $consentPreferencesManagement;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->customerUrl = $this->createStub(CustomerUrl::class);
        $this->consentPreferencesManagement = $this->createMock(ConsentPreferencesManagementInterface::class);
    }

    private function makeController(): SetChannel
    {
        return new SetChannel(
            $this->makeContext(),
            $this->customerSession,
            $this->customerUrl,
            $this->consentPreferencesManagement
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetsConsentAndRedirectsWithSuccessMessage(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['channel', 'sms'], ['consented', true]]);

        $this->consentPreferencesManagement->expects(self::once())->method('setMyChannelConsent')->with('sms', true);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddsErrorMessageWhenManagementThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['channel', 'bogus'], ['consented', true]]);

        $exception = new LocalizedException(__('"bogus" is not a valid consent channel.'));
        $this->consentPreferencesManagement->method('setMyChannelConsent')->willThrowException($exception);
        $this->messageManager->expects(self::once())->method('addErrorMessage')->with($exception->getMessage());

        self::assertSame($this->resultRedirect, $controller->execute());
    }
}
