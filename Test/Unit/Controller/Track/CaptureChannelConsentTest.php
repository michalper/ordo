<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Api\Data\ConsentPreferencesInterface;
use Ordo\Automation\Controller\Track\CaptureChannelConsent;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CaptureChannelConsentTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private CustomerSession $customerSession;
    private ConsentPreferencesManagementInterface $consentPreferencesManagement;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->consentPreferencesManagement = $this->createMock(ConsentPreferencesManagementInterface::class);
        $this->config = $this->createStub(Config::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): CaptureChannelConsent
    {
        return new CaptureChannelConsent(
            $this->makeContext(),
            $this->resultJsonFactory,
            // A real resolver over the stubbed session, same convention
            // DismissNotificationTest already uses - identity/CSRF flow from the same request
            // params and session state the controller reads inline.
            new VisitorIdentityResolver($this->customerSession, $this->createStub(CookieManagerInterface::class)),
            $this->consentPreferencesManagement,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isChannelConsentCaptureEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);
        $this->consentPreferencesManagement->expects(self::never())->method('setMyChannelConsent');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsTrueAndSavesConsentWhenEnabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isChannelConsentCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'sms'],
            ['consented', null, '1'],
        ]);
        $this->consentPreferencesManagement->expects(self::once())->method('setMyChannelConsent')
            ->with('sms', true)
            ->willReturn($this->createStub(ConsentPreferencesInterface::class));

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsConsentedFalseWhenUnchecked(): void
    {
        $controller = $this->makeController();
        $this->config->method('isChannelConsentCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'whatsapp'],
            ['consented', null, '0'],
        ]);
        $this->consentPreferencesManagement->expects(self::once())->method('setMyChannelConsent')
            ->with('whatsapp', false)
            ->willReturn($this->createStub(ConsentPreferencesInterface::class));

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => true]);

        $controller->execute();
    }

    /**
     * ConsentPreferencesManagementInterface::setMyChannelConsent() itself throws
     * NoSuchEntityException for a guest (not authenticated) - reported back as the same no-op
     * an invalid channel gets, since there is nothing actionable a theme's own JS could do with
     * either beyond "it didn't save".
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseForAGuest(): void
    {
        $controller = $this->makeController();
        $this->config->method('isChannelConsentCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'sms'],
            ['consented', null, '1'],
        ]);
        $this->consentPreferencesManagement->method('setMyChannelConsent')
            ->willThrowException(new NoSuchEntityException());

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsFalseForAnInvalidChannel(): void
    {
        $controller = $this->makeController();
        $this->config->method('isChannelConsentCaptureEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['channel', null, 'carrier_pigeon'],
            ['consented', null, '1'],
        ]);
        $this->consentPreferencesManagement->method('setMyChannelConsent')
            ->willThrowException(new LocalizedException(__('"carrier_pigeon" is not a valid consent channel.')));

        $this->jsonResult->expects(self::once())->method('setData')->with(['ok' => false]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrueForAnonymousVisitor(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }

    /**
     * Same Origin/Referer trust model as every other Controller\Track\* endpoint
     * (VisitorIdentityResolver::validateForCsrf()) - a bare fetch() call has no page-rendered
     * form_key to attach, so a logged-in request is checked against Origin/Referer instead.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfRejectsMismatchedOriginForLoggedInCustomer(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $controller = $this->makeController();
        $this->request->method('getHeader')->willReturnMap([
            ['Origin', 'https://evil.example'],
        ]);
        $this->request->method('getHttpHost')->willReturn('my-store.example');

        self::assertFalse($controller->validateForCsrf($this->request));
    }
}
