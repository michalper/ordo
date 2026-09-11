<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Campaign;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\CampaignSaveProcessor;
use Ordo\Automation\Plugin\Campaign\CampaignSaveProcessorAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignSaveProcessorAuditPluginTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundProcessRecordsCreateWithNoDiffWhenNoEntityIdPosted(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);

        $recorder = $this->createMock(Recorder::class);
        $recorder->expects(self::once())->method('record')->with('campaign', 5, AdminActionLog::ACTION_CREATE, null);
        $recorder->expects(self::never())->method('diffFields');

        $subject = $this->createStub(CampaignSaveProcessor::class);
        $plugin = new CampaignSaveProcessorAuditPlugin($recorder);

        $result = $plugin->aroundProcess($subject, fn () => $campaign, []);

        self::assertSame($campaign, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundProcessRecordsUpdateWithDiffWhenEntityIdPosted(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);

        $recorder = $this->createMock(Recorder::class);
        $recorder->expects(self::once())->method('diffFields')->with($campaign, ['name', 'enabled', 'condition_logic'])
            ->willReturn(['name' => ['Old', 'New']]);
        $recorder->expects(self::once())->method('record')
            ->with('campaign', 5, AdminActionLog::ACTION_UPDATE, ['name' => ['Old', 'New']]);

        $subject = $this->createStub(CampaignSaveProcessor::class);
        $plugin = new CampaignSaveProcessorAuditPlugin($recorder);

        $plugin->aroundProcess($subject, fn () => $campaign, ['entity_id' => 5]);
    }
}
