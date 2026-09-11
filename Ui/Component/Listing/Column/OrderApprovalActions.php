<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

use Magento\Framework\Exception\NoSuchEntityException;
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
 * Delegates the actual URL-building to OrderApprovalManagementInterface::getDecisionLinksById()
 * (the same service a headless client already uses, per that interface's own docblock) rather
 * than re-deriving the token URL shape here.
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

        foreach ($dataSource['data']['items'] as &$item) {
            if (($item['status'] ?? null) !== OrderApproval::STATUS_PENDING) {
                continue;
            }

            $entityId = (int) $item['entity_id'];

            try {
                $links = $this->orderApprovalManagement->getDecisionLinksById($entityId);
            } catch (NoSuchEntityException) {
                // Already decided by the time the grid rendered (race with the token link/cron) -
                // no actions to show for this row, not an error worth logging.
                continue;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: could not build decision links for order approval #%d: %s',
                    $entityId,
                    $e->getMessage()
                ));
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
