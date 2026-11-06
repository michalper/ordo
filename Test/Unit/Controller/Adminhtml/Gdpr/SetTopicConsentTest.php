<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Gdpr;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Gdpr\SetTopicConsent;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SetTopicConsentTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenCustomerIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['customer_id', null],
            ['channel', 'email'],
            ['topic', 'promotional'],
            ['consented', '1'],
            ['email', ''],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::never())->method('setTopicConsent');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new SetTopicConsent($context, $consentManager);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenChannelInvalid(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['customer_id', 42],
            ['channel', 'carrier_pigeon'],
            ['topic', 'promotional'],
            ['consented', '1'],
            ['email', ''],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::never())->method('setTopicConsent');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new SetTopicConsent($context, $consentManager);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenTopicInvalid(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['customer_id', 42],
            ['channel', 'email'],
            ['topic', 'carrier_pigeon'],
            ['consented', '1'],
            ['email', ''],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::never())->method('setTopicConsent');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new SetTopicConsent($context, $consentManager);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSetsTopicConsentAndRedirectsOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['customer_id', 42],
            ['channel', ConsentChannel::Email->value],
            ['topic', ConsentTopic::AccountUpdates->value],
            ['consented', '0'],
            ['email', 'jan@example.com'],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('*/*/index', ['email' => 'jan@example.com'])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::once())->method('setTopicConsent')
            ->with(42, ConsentChannel::Email, ConsentTopic::AccountUpdates, false, 'admin');
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $controller = new SetTopicConsent($context, $consentManager);
        self::assertSame($redirect, $controller->execute());
    }
}
