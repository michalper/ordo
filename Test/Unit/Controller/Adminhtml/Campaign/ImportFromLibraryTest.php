<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Campaign\ImportFromLibrary;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\CampaignImporter;
use Ordo\Automation\Model\Campaign\TemplateLibrary;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ImportFromLibraryTest extends AbstractAdminActionTestCase
{
    private TemplateLibrary $templateLibrary;
    private CampaignImporter $campaignImporter;
    private Redirect $redirect;

    protected function setUp(): void
    {
        $this->templateLibrary = $this->createMock(TemplateLibrary::class);
        $this->campaignImporter = $this->createMock(CampaignImporter::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
    }

    private function makeController(): ImportFromLibrary
    {
        $context = $this->makeContext();
        $this->resultRedirectFactory->method('create')->willReturn($this->redirect);

        return new ImportFromLibrary($context, $this->templateLibrary, $this->campaignImporter);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheLibraryWhenTheTemplateIdIsUnknown(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['template_id', 'nonexistent']]);
        $this->templateLibrary->method('getTemplate')->with('nonexistent')->willReturn(null);

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->campaignImporter->expects(self::never())->method('import');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/library');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheLibraryWhenTheImporterRejectsThePayload(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['template_id', 'welcome_series']]);
        $this->templateLibrary->method('getTemplate')->with('welcome_series')->willReturn([
            'id' => 'welcome_series',
            'name' => 'Welcome Series',
            'description' => 'x',
            'payload' => ['export_type' => 'ordo_campaign', 'name' => 'Welcome Series'],
        ]);
        $this->campaignImporter->method('import')
            ->willThrowException(new \InvalidArgumentException('The campaign export is missing a name.'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/library');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToTheNewCampaignsEditPageOnSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['template_id', 'welcome_series']]);
        $payload = ['export_type' => 'ordo_campaign', 'name' => 'Welcome Series'];
        $this->templateLibrary->method('getTemplate')->with('welcome_series')->willReturn([
            'id' => 'welcome_series',
            'name' => 'Welcome Series',
            'description' => 'x',
            'payload' => $payload,
        ]);

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(42);
        $campaign->method('getName')->willReturn('Welcome Series');
        $this->campaignImporter->expects(self::once())->method('import')->with($payload)->willReturn($campaign);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/edit', ['entity_id' => 42]);

        $controller->execute();
    }
}
