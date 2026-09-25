<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Ordo\Automation\Api\OrderApprovalManagementInterface;
use Ordo\Automation\Model\OrderApproval;
use Psr\Log\LoggerInterface;

/**
 * Renders the same approve/reject links the original decision email carries, for a still-pending
 * row only — closes the "no admin grid for order approvals at all" ROADMAP.md gap: an admin who
 * lost the original email previously had no in-backend way to act on a pending approval.
 * Delegates the actual URL-building to OrderApprovalManagementInterface::getDecisionLinksByIds()
 * (the batched form of getDecisionLinksById(), the same service a headless client already uses
 * one-at-a-time, per that interface's own docblock) rather than re-deriving the token URL shape
 * here, and rather than calling it once per row (a real N+1 a code audit found).
 */
class OrderApprovalActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly OrderApprovalManagementInterface $orderApprovalManagement,
        private readonly LoggerInterface $logger,
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

        $pendingIds = [];
        foreach ($dataSource['data']['items'] as $item) {
            if (($item['status'] ?? null) === OrderApproval::STATUS_PENDING) {
                $pendingIds[] = (int) $item['entity_id'];
            }
        }

        if ($pendingIds === []) {
            return $dataSource;
        }

        // One batched call for the whole page instead of one getDecisionLinksById() per row - an
        // N+1 (each of those doing its own approval + order load) found by a code audit. A row
        // absent from the returned map is either already decided (race with the token link/cron)
        // or a genuine failure building its links - either way, "no actions to show" for that
        // row, not an error worth surfacing per-row.
        try {
            $linksByEntityId = $this->orderApprovalManagement->getDecisionLinksByIds($pendingIds);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: could not build decision links for pending order approvals: %s',
                $e->getMessage()
            ));
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $links = $linksByEntityId[(int) $item['entity_id']] ?? null;
            if ($links === null) {
                continue;
            }

            $item[$this->getData('name')] = [
                'approve' => [
                    'href' => $links->getApproveUrl(),
                    'label' => __('Approve'),
                ],
                'reject' => [
                    'href' => $links->getRejectUrl(),
                    'label' => __('Reject'),
                    'confirm' => [
                        'title' => __('Reject order'),
                        'message' => __('Are you sure you want to reject this order?'),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
