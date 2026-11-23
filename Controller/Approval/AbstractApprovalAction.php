<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Approval;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Both Approve and Reject accept GET (render a confirmation page for the token-authenticated
 * decision link in an email) and POST (actually perform the decision, submitted by that page's
 * own form) on the SAME URL/action - execute() itself branches on the request method, the same
 * single-controller-handles-both-verbs shape Controller\Email\Unsubscribe uses for RFC 8058's
 * List-Unsubscribe-Post, except this one deliberately DIFFERS by method (render vs. act) rather
 * than doing the same thing for either.
 *
 * GET used to perform the decision directly - a real, exploitable gap: corporate email-security
 * gateways (Microsoft Defender Safe Links, Proofpoint URL Defense, Mimecast) and some mail
 * clients routinely pre-fetch/pre-render every URL in an inbound email before, or instead of, the
 * recipient ever clicking it. Since the decision token is single-use (claimed atomically in
 * Model\OrderApprovalManagement), that automated prefetch silently and permanently approved or
 * canceled a real order, burning the token before the actual recipient (e.g. a manager) ever saw
 * the email - the opposite of what this feature exists to guarantee. Moving the real decision
 * behind a genuine POST, triggered only by a human clicking the confirm page's own button, closes
 * this - the same reasoning RFC 8058 already established for one-click email actions.
 *
 * CSRF-exempt for the same reason Controller\Email\Unsubscribe is: this is a sessionless, token-
 * authenticated anonymous endpoint - the secret token in the URL/form IS the authentication,
 * there's no session/cookie-based login here for a forged cross-site request to exploit.
 */
abstract class AbstractApprovalAction extends Action implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    CsrfAwareActionInterface
{
    /**
     * getRequest() is typed RequestInterface, which has no isPost() - only the concrete
     * Http class does. Always Http in a real web request (CsrfAwareActionInterface's own
     * methods take RequestInterface too, for the same reason), but an instanceof guard keeps
     * this statically safe instead of asserting the narrower type away.
     */
    protected function isPostRequest(): bool
    {
        $request = $this->getRequest();

        return $request instanceof Http && $request->isPost();
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
     * The GET-rendered confirmation page a human must actually click through before the
     * corresponding POST (same URL, this class's own execute()) performs the real decision - see
     * this class's own docblock. The token itself is never validated here: validation only
     * happens once the form is actually submitted, so an automated prefetch of this page alone
     * can never consume it.
     */
    protected function renderConfirmPage(): Page
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->set((string) __('Order Approval'));

        return $resultPage;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
