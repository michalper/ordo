<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

class LeadRoutingRuleActions extends AbstractEntityActionsColumn
{
    protected function getEditUrlPath(): string
    {
        return 'ordo/leadroutingrule/edit';
    }

    protected function getDeleteUrlPath(): string
    {
        return 'ordo/leadroutingrule/delete';
    }

    protected function getEntityLabel(): string
    {
        return (string) __('lead routing rule');
    }
}
