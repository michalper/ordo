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
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated endpoint - push-sw.js's own `pushsubscriptionchange` handler (or
 * tracker.js, if the visitor explicitly revokes notification permission) posts here so a dead
 * subscription is removed proactively, rather than only ever being discovered the next time a
 * campaign tries (and fails) to send to it.
 *
 * Ownership is checked before unregistering (PushSubscription::belongsTo(), same mechanism
 * Controller\Track\DismissNotification uses) - the endpoint alone is not proof of ownership, so a
 * request can only delete a subscription that actually belongs to its own customer_id/visitor_id.
 * CSRF trust model mirrors RegisterPushSubscription's own reasoning (VisitorIdentityResolver::
 * validateForCsrf()) - anonymous unregistrations skip CSRF (no session/form key exists yet), a
 * logged-in one is checked against Origin/Referer.
 */
class UnregisterPushSubscription extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly VisitorIdentityResolver $visitorIdentityResolver
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $endpoint = $this->getRequest()->getParam('endpoint');
        $endpoint = is_string($endpoint) ? trim($endpoint) : '';
        if ($endpoint === '') {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        $visitorId = $this->visitorIdentityResolver->resolveVisitorId();
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        $this->pushSubscriptionManager->unregister($endpoint, $customerId, $visitorId);

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
