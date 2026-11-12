<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Gdpr;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Ordo\Automation\Controller\Adminhtml\Gdpr\Export;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Gdpr\CustomerDataExporter;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ExportTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenCustomerIdInvalid(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['customer_id', 0]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $customerDataExporter = $this->createMock(CustomerDataExporter::class);
        $customerDataExporter->expects(self::never())->method('export');
        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::never())->method('getConsentStates');
        $consentManager->expects(self::never())->method('getTopicConsentStates');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $resultRawFactory = $this->createStub(RawFactory::class);

        $controller = new Export($context, $resultRawFactory, $customerDataExporter, $consentManager);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsJsonDownloadOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['customer_id', 42]]);

        $customerDataExporter = $this->createMock(CustomerDataExporter::class);
        $customerDataExporter->expects(self::once())->method('export')->with(42)->willReturn(['tags' => []]);
        $consentManager = $this->createMock(ConsentManager::class);
        $consentManager->expects(self::once())->method('getConsentStates')->with(42)->willReturn(['email' => true]);
        $consentManager->expects(self::exactly(5))->method('getTopicConsentStates')
            ->with(42, self::isInstanceOf(ConsentChannel::class))
            ->willReturn(['promotional' => false]);

        $raw = $this->createMock(Raw::class);
        $raw->expects(self::exactly(2))->method('setHeader')->willReturnSelf();
        $raw->expects(self::once())->method('setContents')
            ->with(self::callback(
                fn ($json) => str_contains($json, '"tags"') && str_contains($json, '"email"')
                    && str_contains($json, '"topic_consent_states"') && str_contains($json, '"promotional": false')
            ))
            ->willReturnSelf();
        $resultRawFactory = $this->createStub(RawFactory::class);
        $resultRawFactory->method('create')->willReturn($raw);

        $controller = new Export($context, $resultRawFactory, $customerDataExporter, $consentManager);
        self::assertSame($raw, $controller->execute());
    }
}
