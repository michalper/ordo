<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

/**
 * EmailTemplateDraft needs a third row action (Publish) and has no "name" field (its display
 * field is template_code) - both now configurable hooks on AbstractEntityActionsColumn, so this
 * no longer needs to re-implement prepareDataSource() from scratch.
 *
 * URLs are fully-qualified ("ordo/emailtemplatedraft/edit"), not "*\/*\/edit" wildcards - a
 * wildcard here resolves against the grid's own AJAX data-source request (mui/index/render),
 * not the page the grid is displayed on, producing a broken "mui/index/edit"-style link. Found
 * by actually clicking Publish in a real browser, not from reading the code.
 */
class EmailTemplateDraftActions extends AbstractEntityActionsColumn
{
    protected function getEditUrlPath(): string
    {
        return 'ordo/emailtemplatedraft/edit';
    }

    protected function getDeleteUrlPath(): string
    {
        return 'ordo/emailtemplatedraft/delete';
    }

    protected function getEntityLabel(): string
    {
        return (string) __('email template draft');
    }

    protected function getEntityNameField(): string
    {
        return 'template_code';
    }

    protected function getExtraActions(array $item): array
    {
        return [
            'publish' => [
                'href' => $this->urlBuilder->getUrl(
                    'ordo/emailtemplatedraft/publish',
                    ['entity_id' => $item['entity_id']]
                ),
                'label' => __('Publish'),
                'post' => true,
                'confirm' => [
                    'title' => __('Publish'),
                    'message' => __(
                        'Publish this draft onto the live template? This immediately changes what send_email sends.'
                    ),
                ],
            ],
        ];
    }
}
