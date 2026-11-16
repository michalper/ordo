<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Segment;

/**
 * "Bulk actions" section on the Segment Edit page — lets an admin run add_tag/add_points against
 * everyone currently matching the segment's conditions, via BulkAction controller.
 * Plain HTML form, no ui_component: only two always-visible fields (tag text input,
 * points number input) gated by an action-type select, kept deliberately simple.
 */
class BulkActions extends AbstractSegmentEditBlock
{
    public function getFormAction(): string
    {
        return $this->getUrl('*/segment/bulkAction');
    }
}
