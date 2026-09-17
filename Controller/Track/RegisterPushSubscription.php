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
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Push\PushEndpointValidator;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated endpoint tracker.js posts to once the visitor grants notification
 * permission and the browser returns a real PushSubscription.
 *
 * CSRF is only skipped for anonymous registrations (no session/form key exists yet, same trust
 * model as Controller\Track\Event's own harmless analytics writes). A LOGGED-IN registration is
 * different in kind, not just degree: it binds an attacker-supplied endpoint/keys to a real
 * customer_id, and every future personalized "send_push" campaign (cart reminders, discount
 * codes) would then be delivered straight to whoever controls that endpoint - so an authenticated
 * request is required to have actually originated from this site (checked via Origin/Referer,
 * since this is a bare fetch() call with no page-rendered form_key to attach).
 *
 * `endpoint` is also validated (Model\Push\PushEndpointValidator) before being persisted at all -
 * without that, this becomes a stored SSRF vector: whatever URL is saved here is what
 * Model\Push\PushSender later makes a real server-side HTTP request to.
 */
class RegisterPushSubscription extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly PushEndpointValidator $pushEndpointValidator,
        private readonly VisitorIdentityResolver $visitorIdentityResolver,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isPushEnabled()) {
            return $result->setData(['ok' => false, 'reason' => 'push_disabled']);
        }

        $endpoint = $this->getRequest()->getParam('endpoint');
        $endpoint = is_string($endpoint) ? trim($endpoint) : '';
        $p256dh = $this->getRequest()->getParam('p256dh');
        $p256dh = is_string($p256dh) ? trim($p256dh) : '';
        $auth = $this->getRequest()->getParam('auth');
        $auth = is_string($auth) ? trim($auth) : '';
        $visitorId = $this->visitorIdentityResolver->resolveVisitorId();
        $hasNoIdentity = $this->visitorIdentityResolver->hasNoIdentity($visitorId);

        if ($endpoint === '' || $p256dh === '' || $auth === '' || $hasNoIdentity) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        if (!$this->pushEndpointValidator->isAllowed($endpoint)) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_endpoint']);
        }

        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        $this->pushSubscriptionManager->register($endpoint, $p256dh, $auth, $customerId, $visitorId ?: null);

        return $result->setData(['ok' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->visitorIdentityResolver->validateForCsrf($request);
    }
}
