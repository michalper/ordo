<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Segment;

/**
 * "Estimated audience size" counter on the Segment Edit page - an explicit-refresh, on-demand
 * equivalent of the segment grid's cached estimated_audience_size column (see
 * Model\Segment\SegmentAudienceSizeRecalculator/Cron\RecalculateSegmentAudienceSizes for that
 * one). One segment in view here, so re-resolving live via SegmentMemberResolver on a button
 * click (Controller\Adminhtml\Segment\AudienceSize) is cheap enough not to need caching, and
 * gives an exact answer instead of a snapshot that might be up to 15 minutes stale.
 */
class AudienceSize extends AbstractSegmentEditBlock
{
    public function getAudienceSizeUrl(): string
    {
        return $this->getUrl('*/segment/audienceSize');
    }
}
