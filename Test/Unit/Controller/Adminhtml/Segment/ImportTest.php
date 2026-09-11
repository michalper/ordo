<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Segment\Import;
use Ordo\Automation\Model\Import\UploadedJsonFileReader;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentImporter;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ImportTest extends AbstractAdminActionTestCase
{
    private SegmentImporter $segmentImporter;
    private UploadedJsonFileReader $uploadedJsonFileReader;
    private Redirect $redirect;

    protected function setUp(): void
    {
        $this->segmentImporter = $this->createMock(SegmentImporter::class);
        $this->uploadedJsonFileReader = $this->createMock(UploadedJsonFileReader::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
    }

    private function makeController(): Import
    {
        $context = $this->makeContext();
        $this->resultRedirectFactory->method('create')->willReturn($this->redirect);

        return new Import($context, $this->segmentImporter, $this->uploadedJsonFileReader);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheImportFormWhenTheUploadedFileCannotBeRead(): void
    {
        $controller = $this->makeController();
        $this->uploadedJsonFileReader->method('read')
            ->willThrowException(new \InvalidArgumentException('Choose a file to import.'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->segmentImporter->expects(self::never())->method('import');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/importform');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheImportFormWhenTheImporterRejectsThePayload(): void
    {
        $controller = $this->makeController();
        $this->uploadedJsonFileReader->method('read')->willReturn(['export_type' => 'ordo_campaign']);
        $this->segmentImporter->method('import')
            ->willThrowException(new \InvalidArgumentException('This file is not a segment export.'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/importform');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheNewSegmentsEditPageOnSuccess(): void
    {
        $controller = $this->makeController();
        $this->uploadedJsonFileReader->method('read')->willReturn(['export_type' => 'ordo_segment', 'name' => 'x']);

        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(42);
        $segment->method('getName')->willReturn('VIPs');
        $this->segmentImporter->method('import')->willReturn($segment);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/edit', ['entity_id' => 42]);

        $controller->execute();
    }
}
