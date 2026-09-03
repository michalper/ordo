<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

class ContentBlockActions extends AbstractEntityActionsColumn
{
    protected function getEditUrlPath(): string
    {
        return 'ordo/contentblock/edit';
    }

    protected function getDeleteUrlPath(): string
    {
        return 'ordo/contentblock/delete';
    }

    protected function getEntityLabel(): string
    {
        return (string) __('content block');
    }
}
