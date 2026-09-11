<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;

/**
 * Shared "load the reorder cycle named by this request's entity_id, or bail with the right error
 * message" boilerplate - SendReminder and BuildCart both had this identically (found via
 * SonarCloud flagging the duplication), the only two of this grid's one-click row actions with a
 * real side effect (RecalculateNow is read-only and Index is the grid itself, neither needed it).
 */
abstract class AbstractReorderCycleAction extends Action
{
    // Same resource Controller\Adminhtml\ReorderCycle\Index already gates on.
    public const ADMIN_RESOURCE = 'Ordo_Automation::reorder_cycle';

    public function __construct(
        Context $context,
        protected readonly ReorderCycleFactory $reorderCycleFactory,
        protected readonly ReorderCycleResource $reorderCycleResource
    ) {
        parent::__construct($context);
    }

    /**
     * @return ReorderCycle|null null once the appropriate error message has already been queued
     *   (missing entity_id, or that cycle no longer exists) - caller should redirect back
     *   immediately in that case, never proceed to whatever the row action itself does.
     */
    protected function loadCycleOrFail(): ?ReorderCycle
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing reorder cycle id.'));
            return null;
        }

        $cycle = $this->reorderCycleFactory->create();
        $this->reorderCycleResource->load($cycle, $entityId);

        if (!$cycle->getEntityId()) {
            $this->messageManager->addErrorMessage(__('That reorder cycle no longer exists.'));
            return null;
        }

        return $cycle;
    }
}
