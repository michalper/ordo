<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Segment\SegmentAudienceSizeHistory as SegmentAudienceSizeHistoryResource;

/**
 * One append-only snapshot row per SegmentAudienceSizeRecalculator::recalculateAll() pass, one
 * segment at a time - closes the "how has this segment grown/shrunk over the last 3 months"
 * ROADMAP.md gap, where ordo_segment.estimated_audience_size/audience_size_computed_at only ever
 * held the latest snapshot (each recalculation overwrites the previous one in place). No admin
 * trend view yet - a natural follow-up once there is real history to show.
 */
class SegmentAudienceSizeHistory extends AbstractModel
{
    public const string SEGMENT_ID = 'segment_id';
    public const string AUDIENCE_SIZE = 'audience_size';
    public const string COMPUTED_AT = 'computed_at';

    protected function _construct(): void
    {
        $this->_init(SegmentAudienceSizeHistoryResource::class);
    }

    public function getSegmentId(): int
    {
        return (int) $this->getData(self::SEGMENT_ID);
    }

    public function setSegmentId(int $segmentId): self
    {
        $this->setData(self::SEGMENT_ID, $segmentId);
        return $this;
    }

    public function getAudienceSize(): int
    {
        return (int) $this->getData(self::AUDIENCE_SIZE);
    }

    public function setAudienceSize(int $audienceSize): self
    {
        $this->setData(self::AUDIENCE_SIZE, $audienceSize);
        return $this;
    }

    public function getComputedAt(): ?string
    {
        $value = $this->getData(self::COMPUTED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setComputedAt(string $computedAt): self
    {
        $this->setData(self::COMPUTED_AT, $computedAt);
        return $this;
    }
}
