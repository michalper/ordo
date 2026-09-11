<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\WhatsAppTemplate;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Plugin\WhatsAppTemplate\WhatsAppTemplateResourceAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateResourceAuditPluginTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveSkipsRecordingWithNoLoggedInAdmin(): void
    {
        $model = $this->createStub(WhatsAppTemplate::class);
        $subject = $this->createStub(WhatsAppTemplateResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        $plugin = new WhatsAppTemplateResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsCreateWithNoDiffWhenModelHasNoId(): void
    {
        $model = $this->createStub(WhatsAppTemplate::class);
        $model->method('getId')->willReturn(null);
        $subject = $this->createStub(WhatsAppTemplateResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::never())->method('diffFields');
        $recorder->expects(self::once())->method('record')
            ->with('whatsapp_template', 0, AdminActionLog::ACTION_CREATE, null);

        $plugin = new WhatsAppTemplateResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    /**
     * This same resource is saved by SubmitForReview/RefreshStatus too (status transitions),
     * not just Save - "status" is deliberately part of the audited fields for that reason.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsUpdateWithDiffWhenModelAlreadyHasAnId(): void
    {
        $model = $this->createStub(WhatsAppTemplate::class);
        $model->method('getId')->willReturn(7);
        $subject = $this->createStub(WhatsAppTemplateResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::once())->method('diffFields')
            ->with($model, ['name', 'meta_template_name', 'category', 'language', 'body_text', 'status'])
            ->willReturn(['status' => ['pending', 'approved']]);
        $recorder->expects(self::once())->method('record')
            ->with('whatsapp_template', 7, AdminActionLog::ACTION_UPDATE, ['status' => ['pending', 'approved']]);

        $plugin = new WhatsAppTemplateResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }
}
