<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Ordo\Automation\Model\Segment\SegmentAudienceSizeHistory;
use Ordo\Automation\Test\Unit\Model\AbstractModelTestCase;

class SegmentAudienceSizeHistoryTest extends AbstractModelTestCase
{
    private function makeModel(): SegmentAudienceSizeHistory
    {
        return new SegmentAudienceSizeHistory($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testSegmentIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setSegmentId(7);
        self::assertSame(7, $model->getSegmentId());
    }

    public function testAudienceSizeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAudienceSize(42);
        self::assertSame(42, $model->getAudienceSize());
    }

    public function testComputedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getComputedAt());

        $model->setComputedAt('2026-01-01 00:00:00');
        self::assertSame('2026-01-01 00:00:00', $model->getComputedAt());
    }
}
