<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

abstract class AbstractApprovalAction extends Action implements HttpGetActionInterface
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    protected function redirectHome(string $message, bool $isSuccess = true): Redirect
    {
        if ($isSuccess) {
            $this->messageManager->addSuccessMessage(__($message));
        } else {
            $this->messageManager->addErrorMessage(__($message));
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('/');
    }
}
