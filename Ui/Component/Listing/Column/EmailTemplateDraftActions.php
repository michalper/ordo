<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Not an AbstractEntityActionsColumn subclass - that shared base hardcodes exactly edit/delete
 * and reads the confirm text's entity name off $item['name'], but EmailTemplateDraft has no
 * "name" field (its display field is template_code) and needs a third row action, Publish.
 * Reuses the same already-translated "Delete %1 \"%2\""/"Are you sure you want to delete this
 * %1?" phrases every other entity's delete confirm uses (AbstractEntityActionsColumn), just
 * sourced from template_code instead of name.
 *
 * URLs are fully-qualified ("ordo/emailtemplatedraft/edit"), not "*\/*\/edit" wildcards - a
 * wildcard here resolves against the grid's own AJAX data-source request (mui/index/render),
 * not the page the grid is displayed on, producing a broken "mui/index/edit"-style link. Found
 * by actually clicking Publish in a real browser, not from reading the code.
 */
class EmailTemplateDraftActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $entityLabel = (string) __('email template draft');

        foreach ($dataSource['data']['items'] as &$item) {
            $entityId = $item['entity_id'];
            $templateCode = (string) ($item['template_code'] ?? '');

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('ordo/emailtemplatedraft/edit', ['entity_id' => $entityId]),
                    'label' => __('Edit'),
                ],
                'publish' => [
                    'href' => $this->urlBuilder->getUrl('ordo/emailtemplatedraft/publish', ['entity_id' => $entityId]),
                    'label' => __('Publish'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Publish'),
                        'message' => __(
                            'Publish this draft onto the live template? This immediately changes what send_email sends.'
                        ),
                    ],
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl('ordo/emailtemplatedraft/delete', ['entity_id' => $entityId]),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete %1 "%2"', $entityLabel, $templateCode),
                        'message' => __('Are you sure you want to delete this %1?', $entityLabel),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
