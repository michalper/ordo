<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Consent;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Controller\Consent\SetTopic;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SetTopicTest extends AbstractFrontendActionTestCase
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

    private function makeController(): SetTopic
    {
        return new SetTopic(
            $this->makeContext(),
            $this->customerSession,
            $this->customerUrl,
            $this->consentPreferencesManagement
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetsTopicConsentAndRedirectsWithSuccessMessage(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['channel', 'email'],
            ['topic', 'promotional'],
            ['consented', false],
        ]);

        $this->consentPreferencesManagement->expects(self::once())->method('setMyTopicConsent')
            ->with('email', 'promotional', false);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddsErrorMessageWhenManagementThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([
            ['channel', 'email'],
            ['topic', 'bogus'],
            ['consented', true],
        ]);

        $exception = new LocalizedException(__('"email"/"bogus" is not a valid consent channel/topic pair.'));
        $this->consentPreferencesManagement->method('setMyTopicConsent')->willThrowException($exception);
        $this->messageManager->expects(self::once())->method('addErrorMessage')->with($exception->getMessage());

        self::assertSame($this->resultRedirect, $controller->execute());
    }
}
