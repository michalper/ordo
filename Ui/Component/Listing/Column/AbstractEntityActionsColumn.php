<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Every admin grid in this module renders its row actions the same way: an Edit link, a
 * Delete link with a confirm dialog naming the row by its "name" field. The only thing that
 * ever differed between CampaignActions/FreeGiftOfferActions/SegmentActions was the two URL
 * paths and the entity's display name in the confirm text — this pulls that shared shape into
 * one place instead of three copies drifting independently (flagged as 77.8% duplication by
 * SonarCloud before this refactor).
 */
abstract class AbstractEntityActionsColumn extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        protected readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    abstract protected function getEditUrlPath(): string;

    abstract protected function getDeleteUrlPath(): string;

    /**
     * e.g. "campaign", "segment", "free gift offer" — used in the delete confirm text
     * ("Delete {label} \"%1\"", "Are you sure you want to delete this {label}?").
     */
    abstract protected function getEntityLabel(): string;

    /**
     * Null (the default) omits the "Export" row action entirely — most entities in this module
     * have no export controller. Override to opt in (e.g. "ordo/campaign/export").
     */
    protected function getExportUrlPath(): ?string
    {
        return null;
    }

    /**
     * Which posted field the delete confirm text names the row by - "name" for every entity
     * except EmailTemplateDraft, which has no "name" field (its display field is
     * template_code).
     */
    protected function getEntityNameField(): string
    {
        return 'name';
    }

    /**
     * Extra row actions beyond edit/(export)/delete, inserted between them in this order -
     * EmailTemplateDraftActions' own Publish action is the only current override.
     *
     * @param array<string, mixed> $item
     * @return array<string, array<string, mixed>>
     */
    protected function getExtraActions(array $item): array
    {
        return [];
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $entityId = $item['entity_id'];

            $actions = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl($this->getEditUrlPath(), ['entity_id' => $entityId]),
                    'label' => __('Edit'),
                ],
            ];

            $exportUrlPath = $this->getExportUrlPath();
            if ($exportUrlPath !== null) {
                $actions['export'] = [
                    'href' => $this->urlBuilder->getUrl($exportUrlPath, ['entity_id' => $entityId]),
                    'label' => __('Export'),
                ];
            }

            $actions += $this->getExtraActions($item);

            $actions['delete'] = [
                'href' => $this->urlBuilder->getUrl($this->getDeleteUrlPath(), ['entity_id' => $entityId]),
                'label' => __('Delete'),
                // 'post' => true makes Magento_Ui/js/grid/columns/actions submit this as a real
                // POST (with the admin form key attached) instead of just navigating the
                // browser to $href - the delete controllers behind this all now implement
                // HttpPostActionInterface, not HttpGetActionInterface, specifically so a
                // crafted GET link/<img> tag can no longer trigger a delete for a logged-in
                // admin (no CSRF form-key check applies to a plain GET dispatch).
                'post' => true,
                'confirm' => [
                    'title' => __('Delete %1 "%2"', $this->getEntityLabel(), $item[$this->getEntityNameField()]),
                    'message' => __('Are you sure you want to delete this %1?', $this->getEntityLabel()),
                ],
            ];

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
