<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

class CampaignActions extends AbstractEntityActionsColumn
{
    protected function getEditUrlPath(): string
    {
        return 'ordo/campaign/edit';
    }

    protected function getDeleteUrlPath(): string
    {
        return 'ordo/campaign/delete';
    }

    protected function getExportUrlPath(): ?string
    {
        return 'ordo/campaign/export';
    }

    protected function getEntityLabel(): string
    {
        return (string) __('campaign');
    }
}
