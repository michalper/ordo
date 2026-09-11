<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\ScoreRule;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Plugin\ScoreRule\ScoreRuleResourceAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ScoreRuleResourceAuditPluginTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveSkipsRecordingWithNoLoggedInAdmin(): void
    {
        $model = $this->createStub(ScoreRule::class);
        $subject = $this->createStub(ScoreRuleResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        $plugin = new ScoreRuleResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsCreateWithNoDiffWhenModelHasNoId(): void
    {
        $model = $this->createStub(ScoreRule::class);
        $model->method('getId')->willReturn(null);
        $subject = $this->createStub(ScoreRuleResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::never())->method('diffFields');
        $recorder->expects(self::once())->method('record')
            ->with('score_rule', 0, AdminActionLog::ACTION_CREATE, null);

        $plugin = new ScoreRuleResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsUpdateWithDiffWhenModelAlreadyHasAnId(): void
    {
        $model = $this->createStub(ScoreRule::class);
        $model->method('getId')->willReturn(7);
        $subject = $this->createStub(ScoreRuleResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::once())->method('diffFields')
            ->with($model, ['attribute_code', 'operator', 'value', 'points', 'enabled'])
            ->willReturn(['points' => [10, 20]]);
        $recorder->expects(self::once())->method('record')
            ->with('score_rule', 7, AdminActionLog::ACTION_UPDATE, ['points' => [10, 20]]);

        $plugin = new ScoreRuleResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }
}
