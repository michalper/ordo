<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\AdAudience;

use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Plugin\AdAudience\AdAudienceResourceAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AdAudienceResourceAuditPluginTest extends TestCase
{
    /**
     * Regression test for the exact reason this plugin needs the hasLoggedInAdmin() guard:
     * Cron\SyncAdAudiences calls AdAudienceResource::save() directly, on its own schedule, with
     * no admin session behind it - that save must never be recorded as an admin action.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveSkipsRecordingWithNoLoggedInAdmin(): void
    {
        $model = $this->createStub(AdAudience::class);
        $subject = $this->createStub(AdAudienceResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        $plugin = new AdAudienceResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsCreateWithNoDiffWhenModelHasNoId(): void
    {
        $model = $this->createStub(AdAudience::class);
        $model->method('getId')->willReturn(null);
        $subject = $this->createStub(AdAudienceResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::never())->method('diffFields');
        $recorder->expects(self::once())->method('record')
            ->with('ad_audience', 0, AdminActionLog::ACTION_CREATE, null);

        $plugin = new AdAudienceResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsUpdateWithDiffWhenModelAlreadyHasAnId(): void
    {
        $model = $this->createStub(AdAudience::class);
        $model->method('getId')->willReturn(7);
        $subject = $this->createStub(AdAudienceResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::once())->method('diffFields')
            ->with($model, ['name', 'segment_id', 'platform', 'enabled'])
            ->willReturn(['enabled' => [false, true]]);
        $recorder->expects(self::once())->method('record')
            ->with('ad_audience', 7, AdminActionLog::ACTION_UPDATE, ['enabled' => [false, true]]);

        $plugin = new AdAudienceResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }
}
