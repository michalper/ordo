<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;

/**
 * Mass-delete for the "ids" selectionsColumn this grid's checkboxes render — closes the
 * ROADMAP.md admin-platform gap noting ReorderCycle had no mass action of any kind. Doesn't
 * extend AbstractReorderCycleAction: that class's loadCycleOrFail() is single-entity-id
 * boilerplate SendReminder/BuildCart share, nothing this mass action needs. Deleting a row here
 * just clears a Cron\CalculateReorderCycle prediction early — the same row (or a fresh one) is
 * recomputed on the next cron tick from real order history, same as ReorderCycleActions'
 * docblock already notes about why this grid has no single-row delete either. Same
 * Ui\Component\MassAction\Filter + CollectionFactory + resource-delete-per-row pattern as every
 * other MassDelete in this module.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::reorder_cycle';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly ReorderCycleCollectionFactory $reorderCycleCollectionFactory,
        private readonly ReorderCycleResource $reorderCycleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->reorderCycleCollectionFactory->create());

        $count = 0;
        foreach ($collection as $reorderCycle) {
            /** @var \Ordo\Automation\Model\ReorderCycle $reorderCycle */
            $this->reorderCycleResource->delete($reorderCycle);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 reorder cycle(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
