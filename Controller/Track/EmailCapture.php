<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Newsletter\Model\SubscriberFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\VisitorTagManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public, unauthenticated endpoint - called by the email-capture popup
 * (view/frontend/web/js/tracker.js's startEmailCaptureTimer()/renderEmailCapture()) once a
 * visitor submits an email. Same trust model as Controller\Track\Event's own doc comment: no
 * CSRF token, callable by anonymous visitors with no session/form key yet.
 *
 * Subscribes through Magento's own real Magento\Newsletter\Model\Subscriber - not a new
 * subscription mechanism, so this respects a store's existing newsletter config (confirmation
 * email requirement, etc.) exactly like the storefront's own newsletter signup form would.
 *
 * Also tags the visitor via the existing VisitorTagManager (`newsletter_signup`) - this fires
 * "ordo_visitor_tag_added" (see that class's own doc comment), which
 * Observer\DispatchVisitorTagAddedCampaigns already dispatches as a `visitor_tag_added` campaign
 * trigger. Zero new campaign-engine plumbing: a store builds "what happens after someone signs
 * up" (send a welcome email, apply a discount tag, …) entirely with the existing trigger the same
 * way any other visitor tag already works, see ADR 0043.
 */
class EmailCapture extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const string NEWSLETTER_SIGNUP_TAG = 'newsletter_signup';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly VisitorTagManager $visitorTagManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isEmailCaptureEnabled()) {
            return $result->setData(['ok' => false, 'reason' => 'disabled']);
        }

        $visitorId = $this->getRequest()->getParam('visitor_id');
        $visitorId = is_string($visitorId) ? $visitorId : '';
        $email = $this->getRequest()->getParam('email');
        $email = is_string($email) ? trim($email) : '';

        if ($visitorId === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        try {
            $this->subscriberFactory->create()->subscribe($email);
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Ordo_Automation: email capture subscribe failed: %s', $e->getMessage()));
            return $result->setData(['ok' => false, 'reason' => 'subscribe_failed']);
        }

        $this->visitorTagManager->addTag($visitorId, self::NEWSLETTER_SIGNUP_TAG);

        return $result->setData(['ok' => true]);
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
