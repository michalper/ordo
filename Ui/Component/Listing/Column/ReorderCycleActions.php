<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * A single "Send Reminder Now" row action - closes the ROADMAP.md "no manual per-customer
 * reminder trigger" gap. No edit/delete here (unlike AbstractEntityActionsColumn's shape) -
 * reorder cycle rows are computed by Cron\CalculateReorderCycle, not admin-authored, so there is
 * nothing to edit and deleting one would just be recomputed on the next cron tick.
 */
class ReorderCycleActions extends Column
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

        foreach ($dataSource['data']['items'] as &$item) {
            $entityId = (int) $item['entity_id'];

            $item[$this->getData('name')] = [
                'send_reminder' => [
                    'href' => $this->urlBuilder->getUrl(
                        'ordo/reordercycle/sendreminder',
                        ['entity_id' => $entityId]
                    ),
                    'label' => __('Send Reminder Now'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Send reorder reminder'),
                        'message' => __('Send a reorder reminder email to this customer now?'),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
