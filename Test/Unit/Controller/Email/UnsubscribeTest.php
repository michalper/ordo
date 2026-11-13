<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Email;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Ordo\Automation\Controller\Email\Unsubscribe;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Email\UnsubscribeToken;
use Ordo\Automation\Model\Email\UnsubscribeTokenManager;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class UnsubscribeTest extends AbstractFrontendActionTestCase
{
    private UnsubscribeTokenManager $tokenManager;
    private ConsentManager $consentManager;
    private Page $resultPage;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(UnsubscribeTokenManager::class);
        $this->consentManager = $this->createMock(ConsentManager::class);
    }

    private function makeController(): Unsubscribe
    {
        $this->makeContext();

        $title = $this->createMock(Title::class);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $this->resultPage = $this->createStub(Page::class);
        $this->resultPage->method('getConfig')->willReturn($pageConfig);

        $this->resultFactory = $this->createStub(ResultFactory::class);
        $this->resultFactory->method('create')->willReturn($this->resultPage);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultFactory')->willReturn($this->resultFactory);

        return new Unsubscribe($context, $this->tokenManager, $this->consentManager);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteUnsubscribesTheRightCustomerFromTheRightChannelOnAValidToken(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['token', null, '42.email.sig']]);
        $this->tokenManager->expects(self::once())->method('resolve')->with('42.email.sig')
            ->willReturn(new UnsubscribeToken(42, ConsentChannel::Email));

        $this->consentManager->expects(self::once())->method('setConsent')
            ->with(42, ConsentChannel::Email, false, 'unsubscribe_link');
        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        self::assertSame($this->resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteShowsAnErrorAndDoesNotTouchConsentWhenTokenIsForged(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['token', null, 'forged.token.value']]);
        $this->tokenManager->method('resolve')->willReturn(null);

        $this->consentManager->expects(self::never())->method('setConsent');
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        self::assertSame($this->resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTreatsAMissingTokenParamAsInvalid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['token', null, null]]);
        $this->tokenManager->expects(self::once())->method('resolve')->with('')->willReturn(null);

        $this->consentManager->expects(self::never())->method('setConsent');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrue(): void
    {
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
