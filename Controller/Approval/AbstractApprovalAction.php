<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;

abstract class AbstractApprovalAction extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        protected readonly OrderApprovalFactory $orderApprovalFactory,
        protected readonly OrderApprovalResource $orderApprovalResource
    ) {
        parent::__construct($context);
    }

    /**
     * Looks up the approval by its token, only if it's still pending — an already-decided
     * token is not reusable, so a second click (or a forwarded email) can't flip the decision.
     */
    protected function loadPendingApprovalByToken(): ?OrderApproval
    {
        $token = (string) $this->getRequest()->getParam('token');
        if ($token === '') {
            return null;
        }

        /** @var OrderApproval $approval */
        $approval = $this->orderApprovalFactory->create();
        $this->orderApprovalResource->loadByToken($approval, $token);

        if (!$approval->getId() || !$approval->isPending()) {
            return null;
        }

        return $approval;
    }

    protected function redirectHome(string $message, bool $isSuccess = true)
    {
        if ($isSuccess) {
            $this->messageManager->addSuccessMessage(__($message));
        } else {
            $this->messageManager->addErrorMessage(__($message));
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('/');
    }
}
