<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;

class Reject extends AbstractApprovalAction
{
    public function __construct(
        Context $context,
        OrderApprovalFactory $orderApprovalFactory,
        OrderApprovalResource $orderApprovalResource,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context, $orderApprovalFactory, $orderApprovalResource);
    }

    public function execute()
    {
        $approval = $this->loadPendingApprovalByToken();
        if (!$approval) {
            return $this->redirectHome('This approval link has already been used or is invalid.', false);
        }

        $order = $this->orderCollectionFactory->create()
            ->addFieldToFilter('entity_id', $approval->getData('order_id'))
            ->getFirstItem();

        if (!$order || !$order->getId()) {
            return $this->redirectHome('The order for this approval could not be found.', false);
        }

        // cancel() also releases any reserved inventory back to stock.
        $order->cancel();
        $this->orderRepository->save($order);

        $approval->setData('status', OrderApproval::STATUS_REJECTED);
        $approval->setData('decided_at', date('Y-m-d H:i:s'));
        $this->orderApprovalResource->save($approval);

        return $this->redirectHome(sprintf('Order #%s has been rejected and canceled.', $order->getIncrementId()));
    }
}
