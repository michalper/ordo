<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\ContentBlock;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
use Ordo\Automation\Plugin\ContentBlock\ContentBlockResourceAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ContentBlockResourceAuditPluginTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveSkipsRecordingWithNoLoggedInAdmin(): void
    {
        $model = $this->createStub(ContentBlock::class);
        $subject = $this->createStub(ContentBlockResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        $plugin = new ContentBlockResourceAuditPlugin($recorder);
        $result = $plugin->aroundSave($subject, fn () => $subject, $model);

        self::assertSame($subject, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsCreateWithNoDiffWhenModelHasNoId(): void
    {
        $model = $this->createStub(ContentBlock::class);
        $model->method('getId')->willReturn(null);
        $subject = $this->createStub(ContentBlockResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::never())->method('diffFields');
        $recorder->expects(self::once())->method('record')
            ->with('content_block', 0, AdminActionLog::ACTION_CREATE, null);

        $plugin = new ContentBlockResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsUpdateWithDiffWhenModelAlreadyHasAnId(): void
    {
        $model = $this->createStub(ContentBlock::class);
        $model->method('getId')->willReturn(7);
        $subject = $this->createStub(ContentBlockResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::once())->method('diffFields')
            ->with($model, ['name', 'identifier', 'type', 'enabled'])
            ->willReturn(['name' => ['Old', 'New']]);
        $recorder->expects(self::once())->method('record')
            ->with('content_block', 7, AdminActionLog::ACTION_UPDATE, ['name' => ['Old', 'New']]);

        $plugin = new ContentBlockResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }
}
