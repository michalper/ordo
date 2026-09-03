<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Model\OrderApprovalManagement;

class Reject extends AbstractApprovalAction
{
    public function __construct(
        Context $context,
        private readonly OrderApprovalManagement $orderApprovalManagement,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $token = $this->getRequest()->getParam('token');
        $token = is_string($token) ? $token : '';

        try {
            $approval = $this->orderApprovalManagement->rejectByToken($token);
        } catch (NoSuchEntityException) {
            return $this->redirectHome('This approval link has already been used or is invalid.', false);
        } catch (LocalizedException $e) {
            return $this->redirectHome($e->getMessage(), false);
        }

        $order = $this->orderRepository->get($approval->getOrderId());

        return $this->redirectHome(sprintf('Order #%s has been rejected and canceled.', $order->getIncrementId()));
    }
}
