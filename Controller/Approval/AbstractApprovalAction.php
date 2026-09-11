<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Ordo\Automation\Model\Approval\ApprovalRateLimiter;

abstract class AbstractApprovalAction extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly ApprovalRateLimiter $rateLimiter,
        private readonly RemoteAddress $remoteAddress
    ) {
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

    /**
     * Called by each concrete action before it does anything token-specific - returns a redirect
     * (already carrying the error message) once this token+IP has exceeded
     * ApprovalRateLimiter::MAX_ATTEMPTS within its window, or null to proceed normally.
     */
    protected function enforceRateLimit(string $token): ?Redirect
    {
        $ip = (string) $this->remoteAddress->getRemoteAddress();

        if (!$this->rateLimiter->isAllowed($token, $ip)) {
            return $this->redirectHome('Too many attempts. Please wait a while and try again.', false);
        }

        return null;
    }
}
