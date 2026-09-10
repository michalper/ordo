<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Segment;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Ordo\Automation\Model\Segment;

/**
 * "Estimated audience size" counter on the Segment Edit page - an explicit-refresh, on-demand
 * equivalent of the segment grid's cached estimated_audience_size column (see
 * Model\Segment\SegmentAudienceSizeRecalculator/Cron\RecalculateSegmentAudienceSizes for that
 * one). One segment in view here, so re-resolving live via SegmentMemberResolver on a button
 * click (Controller\Adminhtml\Segment\AudienceSize) is cheap enough not to need caching, and
 * gives an exact answer instead of a snapshot that might be up to 15 minutes stale.
 */
class AudienceSize extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSegment(): ?Segment
    {
        $segment = $this->registry->registry('ordo_segment');
        return $segment instanceof Segment ? $segment : null;
    }

    public function getSegmentId(): int
    {
        $segment = $this->getSegment();
        return $segment instanceof Segment ? (int) $segment->getEntityId() : 0;
    }

    public function isSegmentSaved(): bool
    {
        return $this->getSegmentId() > 0;
    }

    public function getAudienceSizeUrl(): string
    {
        return $this->getUrl('*/segment/audienceSize');
    }
}
