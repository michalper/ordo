<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

class SegmentActions extends AbstractEntityActionsColumn
{
    protected function getEditUrlPath(): string
    {
        return 'ordo/segment/edit';
    }

    protected function getDeleteUrlPath(): string
    {
        return 'ordo/segment/delete';
    }

    protected function getEntityLabel(): string
    {
        return (string) __('segment');
    }
}
