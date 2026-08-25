<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;

class Approve extends AbstractApprovalAction
{
    public function __construct(
        Context $context,
        OrderApprovalFactory $orderApprovalFactory,
        OrderApprovalResource $orderApprovalResource,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderResource $orderResource,
        private readonly OrderConfig $orderConfig
    ) {
        parent::__construct($context, $orderApprovalFactory, $orderApprovalResource);
    }

    public function execute()
    {
        $approval = $this->loadPendingApprovalByToken();
        if (!$approval) {
            return $this->redirectHome('This approval link has already been used or is invalid.', false);
        }

        /** @var Order|null $order */
        $order = $this->orderCollectionFactory->create()
            ->addFieldToFilter('entity_id', $approval->getData('order_id'))
            ->getFirstItem();

        if (!$order || !$order->getId()) {
            return $this->redirectHome('The order for this approval could not be found.', false);
        }

        // Release the order into whatever status is normally the default for the "new" state —
        // i.e. exactly where it would have landed if it had never been held.
        $order->setStatus($this->orderConfig->getStateDefaultStatus(Order::STATE_NEW));
        $this->orderResource->save($order);

        $approval->setData('status', OrderApproval::STATUS_APPROVED);
        $approval->setData('decided_at', date('Y-m-d H:i:s'));
        $this->orderApprovalResource->save($approval);

        return $this->redirectHome(sprintf('Order #%s has been approved.', $order->getIncrementId()));
    }
}
