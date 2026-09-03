<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Segment;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Ordo\Automation\Model\Segment;

/**
 * "Bulk actions" section on the Segment Edit page — lets an admin run add_tag/add_points against
 * everyone currently matching the segment's conditions, via BulkAction controller.
 * Plain HTML form, no ui_component: only two always-visible fields (tag text input,
 * points number input) gated by an action-type select, kept deliberately simple.
 */
class BulkActions extends Template
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
        return $segment instanceof \Ordo\Automation\Model\Segment ? (int) $segment->getEntityId() : 0;
    }

    public function isSegmentSaved(): bool
    {
        return $this->getSegmentId() > 0;
    }

    public function getFormAction(): string
    {
        return $this->getUrl('*/segment/bulkAction');
    }
}
