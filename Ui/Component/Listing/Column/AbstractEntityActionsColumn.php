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
        private readonly UrlInterface $urlBuilder,
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

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $entityId = $item['entity_id'];

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl($this->getEditUrlPath(), ['entity_id' => $entityId]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl($this->getDeleteUrlPath(), ['entity_id' => $entityId]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete %1 "%2"', $this->getEntityLabel(), $item['name']),
                        'message' => __('Are you sure you want to delete this %1?', $this->getEntityLabel()),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
