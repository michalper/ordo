<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Campaign\Import;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\CampaignImporter;
use Ordo\Automation\Model\Import\UploadedJsonFileReader;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ImportTest extends AbstractAdminActionTestCase
{
    private CampaignImporter $campaignImporter;
    private UploadedJsonFileReader $uploadedJsonFileReader;
    private Redirect $redirect;

    protected function setUp(): void
    {
        $this->campaignImporter = $this->createMock(CampaignImporter::class);
        $this->uploadedJsonFileReader = $this->createMock(UploadedJsonFileReader::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
    }

    private function makeController(): Import
    {
        $context = $this->makeContext();
        $this->resultRedirectFactory->method('create')->willReturn($this->redirect);

        return new Import($context, $this->campaignImporter, $this->uploadedJsonFileReader);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheImportFormWhenTheUploadedFileCannotBeRead(): void
    {
        $controller = $this->makeController();
        $this->uploadedJsonFileReader->method('read')
            ->willThrowException(new \InvalidArgumentException('Choose a file to import.'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->campaignImporter->expects(self::never())->method('import');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/importform');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheImportFormWhenTheImporterRejectsThePayload(): void
    {
        $controller = $this->makeController();
        $this->uploadedJsonFileReader->method('read')->willReturn(['export_type' => 'ordo_segment']);
        $this->campaignImporter->method('import')
            ->willThrowException(new \InvalidArgumentException('This file is not a campaign export.'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/importform');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheNewCampaignsEditPageOnSuccess(): void
    {
        $controller = $this->makeController();
        $this->uploadedJsonFileReader->method('read')->willReturn(['export_type' => 'ordo_campaign', 'name' => 'x']);

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(42);
        $campaign->method('getName')->willReturn('Welcome Series');
        $this->campaignImporter->method('import')->willReturn($campaign);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/edit', ['entity_id' => 42]);

        $controller->execute();
    }
}
