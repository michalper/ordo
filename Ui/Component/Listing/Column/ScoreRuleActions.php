<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

class ScoreRuleActions extends AbstractEntityActionsColumn
{
    protected function getEditUrlPath(): string
    {
        return 'ordo/scorerule/edit';
    }

    protected function getDeleteUrlPath(): string
    {
        return 'ordo/scorerule/delete';
    }

    protected function getEntityLabel(): string
    {
        return (string) __('score rule');
    }
}
