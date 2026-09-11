<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Model\Approval\ApprovalRateLimiter;
use Ordo\Automation\Model\OrderApprovalManagement;

class Approve extends AbstractApprovalAction
{
    public function __construct(
        Context $context,
        ApprovalRateLimiter $rateLimiter,
        RemoteAddress $remoteAddress,
        private readonly OrderApprovalManagement $orderApprovalManagement,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context, $rateLimiter, $remoteAddress);
    }

    public function execute()
    {
        $token = $this->getRequest()->getParam('token');
        $token = is_string($token) ? $token : '';

        if (($rateLimited = $this->enforceRateLimit($token)) instanceof \Magento\Framework\Controller\Result\Redirect) {
            return $rateLimited;
        }

        try {
            $approval = $this->orderApprovalManagement->approveByToken($token);
        } catch (NoSuchEntityException) {
            return $this->redirectHome('This approval link has already been used or is invalid.', false);
        } catch (LocalizedException $e) {
            return $this->redirectHome($e->getMessage(), false);
        }

        $order = $this->orderRepository->get($approval->getOrderId());

        return $this->redirectHome(sprintf('Order #%s has been approved.', $order->getIncrementId()));
    }
}
