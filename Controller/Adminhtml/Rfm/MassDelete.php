<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Rfm;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\Rfm\Grid\CollectionFactory as RfmGridCollectionFactory;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The RFM grid's mass action for the "ids" selectionsColumn — closes the ROADMAP.md
 * admin-platform gap noting this grid had no mass action of any kind. Unlike every other
 * MassDelete in this module, there is no stored row to delete: this grid's own collection
 * (Model\ResourceModel\Rfm\Grid\Collection) is customer_entity live-joined to a sales_order
 * aggregate, not a table of Ordo-owned rows with a resource model to delete from — deleting the
 * selected rows here would mean deleting customers, which is not this grid's job.
 *
 * What this entity DOES have that's actually deletable is ordo_customer_rfm_score, the
 * precomputed percentile/quintile cache Cron\RecomputeRfmScores maintains for the *same*
 * customer ids this grid lists (see RfmCalculator::resetScoresForCustomers()'s own docblock).
 * Clearing a selected customer's cached row is this grid's real equivalent of "mass-delete": it
 * throws away nothing that isn't rebuilt by the next scheduled recompute (or computed live in
 * the meantime), matching the read-only/diagnostic nature ROADMAP.md called out for this grid.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::rfm';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly RfmGridCollectionFactory $rfmGridCollectionFactory,
        private readonly RfmCalculator $rfmCalculator
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->rfmGridCollectionFactory->create());

        $customerIds = [];
        foreach ($collection as $customer) {
            /** @var \Magento\Framework\DataObject $customer */
            $entityId = $customer->getData('entity_id');
            if (is_int($entityId) || is_string($entityId)) {
                $customerIds[] = (int) $entityId;
            }
        }

        $this->rfmCalculator->resetScoresForCustomers($customerIds);

        $this->messageManager->addSuccessMessage(
            __(
                'Reset the cached RFM score for %1 customer(s) — recomputed on the next '
                . 'scheduled run (or live in the meantime).',
                count($customerIds)
            )
        );

        return $resultRedirect->setPath('*/*/');
    }
}
