<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Segment;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentSaveProcessor;
use Ordo\Automation\Plugin\Segment\SegmentSaveProcessorAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SegmentSaveProcessorAuditPluginTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundProcessRecordsCreateWithNoDiffWhenNoEntityIdPosted(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(3);

        $recorder = $this->createMock(Recorder::class);
        $recorder->expects(self::once())->method('record')->with('segment', 3, AdminActionLog::ACTION_CREATE, null);
        $recorder->expects(self::never())->method('diffFields');

        $subject = $this->createStub(SegmentSaveProcessor::class);
        $plugin = new SegmentSaveProcessorAuditPlugin($recorder);

        $result = $plugin->aroundProcess($subject, fn () => $segment, []);

        self::assertSame($segment, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundProcessRecordsUpdateWithDiffWhenEntityIdPosted(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(3);

        $recorder = $this->createMock(Recorder::class);
        $recorder->expects(self::once())->method('diffFields')->with($segment, ['name', 'enabled', 'condition_logic'])
            ->willReturn(['enabled' => [false, true]]);
        $recorder->expects(self::once())->method('record')
            ->with('segment', 3, AdminActionLog::ACTION_UPDATE, ['enabled' => [false, true]]);

        $subject = $this->createStub(SegmentSaveProcessor::class);
        $plugin = new SegmentSaveProcessorAuditPlugin($recorder);

        $plugin->aroundProcess($subject, fn () => $segment, ['entity_id' => 3]);
    }
}
