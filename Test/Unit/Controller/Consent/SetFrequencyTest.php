<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Consent;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Controller\Consent\SetFrequency;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SetFrequencyTest extends AbstractFrontendActionTestCase
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

    private function makeController(): SetFrequency
    {
        return new SetFrequency(
            $this->makeContext(),
            $this->customerSession,
            $this->customerUrl,
            $this->consentPreferencesManagement
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetsAPositiveValueAndRedirectsWithSuccessMessage(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('max_messages_per_window')->willReturn('3');

        $this->consentPreferencesManagement->expects(self::once())->method('setMyMaxMessagesPerWindow')->with(3);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnEmptyValueClearsThePreference(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('max_messages_per_window')->willReturn('');

        $this->consentPreferencesManagement->expects(self::once())->method('setMyMaxMessagesPerWindow')->with(null);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testANonNumericValueClearsThePreference(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('max_messages_per_window')->willReturn('abc');

        $this->consentPreferencesManagement->expects(self::once())->method('setMyMaxMessagesPerWindow')->with(null);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddsErrorMessageWhenManagementThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('max_messages_per_window')->willReturn('3');

        $exception = new LocalizedException(__('No such entity.'));
        $this->consentPreferencesManagement->method('setMyMaxMessagesPerWindow')->willThrowException($exception);
        $this->messageManager->expects(self::once())->method('addErrorMessage')->with($exception->getMessage());

        self::assertSame($this->resultRedirect, $controller->execute());
    }
}
