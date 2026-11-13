<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Email;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Email\UnsubscribeToken;
use Ordo\Automation\Model\Email\UnsubscribeTokenManager;

/**
 * The no-login, one-click unsubscribe endpoint every send_email footer link and
 * List-Unsubscribe header (Model\Email\UnsubscribeContext, Plugin\Email\
 * EmailMessageUnsubscribeHeaderPlugin) points at - see docs/adr/0055-email-one-click-unsubscribe.md.
 *
 * Accepts both GET (a human clicking the footer link) and POST (RFC 8058's
 * List-Unsubscribe-Post, a mail client's own one-click button) on the same action - the token
 * itself carries everything needed to act, so there's no reason to duplicate this into two thin
 * controllers the way Controller\Approval\{Approve,Reject} are two actions (those differ in what
 * they DO; this one does the same thing regardless of which verb reached it).
 *
 * Deliberately NOT rate-limited the way Model\Approval\ApprovalRateLimiter guards the order-
 * approval endpoints - see the token's own docblock for why it can't be brute-forced in any
 * meaningful window (a 256-bit HMAC, not a guessable value), and unlike approving someone else's
 * order, an attacker successfully unsubscribing a customer early from marketing email isn't a
 * real security incident worth throttling against.
 *
 * Renders a plain confirmation page directly rather than redirecting to the storefront home page
 * the way Controller\Approval's own redirectHome() does - the whole point of this endpoint is
 * that it works for a logged-out recipient with no account/session context at all, and a
 * dedicated page says what happened without bouncing through an unrelated page first.
 */
class Unsubscribe extends Action implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly UnsubscribeTokenManager $tokenManager,
        private readonly ConsentManager $consentManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $token = $this->getRequest()->getParam('token');
        $token = is_string($token) ? $token : '';

        $payload = $this->tokenManager->resolve($token);
        if (!$payload instanceof UnsubscribeToken) {
            $this->messageManager->addErrorMessage(
                __('This unsubscribe link is invalid.')
            );
        } else {
            $this->consentManager->setConsent(
                $payload->customerId,
                $payload->channel,
                false,
                // Matches ordo_customer_consent_log's own db_schema.xml column comment, which
                // already names this exact value as the convention for a source string.
                'unsubscribe_link'
            );
            $this->messageManager->addSuccessMessage(
                __('You have been unsubscribed and will no longer receive marketing email.')
            );
        }

        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->set((string) __('Email Preferences'));

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
