<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;

/**
 * Refreshes ordo_segment.estimated_audience_size/audience_size_computed_at for every segment -
 * the cached snapshot the segment grid shows (Grid\Collection joins these two columns directly,
 * no per-row resolve). Deliberately a periodic batch job rather than resolving on every grid
 * load: SegmentMemberResolver runs real aggregate queries per condition, and a grid can list many
 * segments at once, so paying that cost once per segment per cron run scales far better than once
 * per segment per admin page view. The segment edit page's own "Estimated audience size" counter
 * is the live, on-demand equivalent for a single segment (Controller\Adminhtml\Segment\
 * AudienceSize), used there instead of this cache because only one segment is in view.
 */
class SegmentAudienceSizeRecalculator
{
    public function __construct(
        private readonly SegmentCollectionFactory $segmentCollectionFactory,
        private readonly SegmentResource $segmentResource,
        private readonly SegmentMemberResolver $segmentMemberResolver,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return int Number of segments refreshed.
     */
    public function recalculateAll(): int
    {
        $collection = $this->segmentCollectionFactory->create();
        $now = $this->dateTime->gmtDate();
        $count = 0;

        /** @var \Ordo\Automation\Model\Segment $segment */
        foreach ($collection as $segment) {
            $segmentId = (int) $segment->getEntityId();
            $size = count($this->segmentMemberResolver->getMatchingCustomerIds($segmentId));

            $segment->setData('estimated_audience_size', $size);
            $segment->setData('audience_size_computed_at', $now);
            $this->segmentResource->save($segment);
            $count++;
        }

        return $count;
    }
}
