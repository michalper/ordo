<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Segment;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Ordo\Automation\Model\Config\Source\SegmentOptions;

/**
 * View model for the Segment Overlap page (view/adminhtml/templates/segment/overlap.phtml,
 * Controller\Adminhtml\Segment\Overlap) - just supplies the two segment dropdowns' options
 * (same Config\Source\SegmentOptions the Ad Audience form already uses) and the AJAX compute
 * endpoint URL; segment-overlap.js does the actual fetch + rendering on "Compare".
 */
class Overlap extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly SegmentOptions $segmentOptions,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function getSegmentOptions(): array
    {
        return $this->segmentOptions->toOptionArray();
    }

    public function getOverlapComputeUrl(): string
    {
        return $this->getUrl('*/segment/overlapCompute');
    }
}
