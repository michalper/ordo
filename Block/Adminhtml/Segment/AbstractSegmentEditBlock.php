<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Segment;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Ordo\Automation\Model\Segment;

/**
 * The "read the segment the edit page's controller stashed in the registry" trio was
 * byte-identical between AudienceSize and BulkActions - both are panels on the same Segment Edit
 * page, reading the same 'ordo_segment' registry key the controller populates. Each panel's own
 * real content (the audience-size AJAX refresh vs. the bulk-action form) stays on the subclass.
 */
abstract class AbstractSegmentEditBlock extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        protected readonly Registry $registry,
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
}
