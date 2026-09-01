<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

class FreeGiftOfferActions extends AbstractEntityActionsColumn
{
    protected function getEditUrlPath(): string
    {
        return 'ordo/freegiftoffer/edit';
    }

    protected function getDeleteUrlPath(): string
    {
        return 'ordo/freegiftoffer/delete';
    }

    protected function getEntityLabel(): string
    {
        return (string) __('free gift offer');
    }
}
