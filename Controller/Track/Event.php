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
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Model\VisitorEventLogger;

/**
 * Public, unauthenticated endpoint the frontend JS tracker (view/frontend/web/js/tracker.js)
 * posts to.
 *
 * CSRF is only skipped for anonymous requests (no session/form key exists yet, same trust model
 * as any third-party "pixel" tracking endpoint) - delegated to VisitorIdentityResolver, same as
 * every other Controller\Track\* endpoint. A LOGGED-IN request is checked against Origin/Referer:
 * this write binds an attacker-chosen event_type/event_key to the real, authenticated
 * customer_id, and VisitorAggregator (triggered once the threshold is crossed) tags that
 * customer's account from it - a first-class input to this module's own campaign/segment
 * targeting and ad-audience sync. Without the check, a forged cross-site POST could silently
 * steer which automated campaign a real customer lands in.
 */
class Event extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const array ALLOWED_EVENT_TYPES = [
        'page_view',
        'product_view',
        'category_view',
        'element_clicked',
        'checkout_start',
        'exit_intent',
        'search_no_results',
        // utm_source/utm_campaign (docs/adr/0085) are automatic, like page_view - tracker.js
        // sends these itself when present in the landing URL's own query string, no theme
        // integration needed. VisitorAggregator tags on the first occurrence (not a repeated-
        // threshold signal like product_view/element_clicked), since a single ad click is the
        // whole signal.
        'utm_source',
        'utm_campaign',
    ];
    private const int MAX_KEY_LENGTH = 255;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly VisitorEventLogger $visitorEventLogger,
        private readonly VisitorIdentityResolver $visitorIdentityResolver,
        private readonly Config $config,
        private readonly EventManagerInterface $eventManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isTrackingEnabled()) {
            return $result->setData(['ok' => false, 'reason' => 'tracking_disabled']);
        }

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $eventType = $this->getRequest()->getParam('event_type');
        $eventType = is_string($eventType) ? $eventType : '';
        $eventKey = $this->getRequest()->getParam('event_key');
        $eventKey = is_scalar($eventKey) ? (string) $eventKey : null;

        if ($visitorId === '' || !in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        $eventKey = $eventKey !== null ? substr($eventKey, 0, self::MAX_KEY_LENGTH) : null;
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        $this->visitorEventLogger->log($visitorId, $eventType, $eventKey, $customerId);

        // exit_intent is a live, anonymous-capable trigger (see
        // Api\Data\CampaignTriggerInterface::TRIGGER_EXIT_INTENT and docs/adr/0037), unlike every
        // other type above, which only ever feeds VisitorAggregator's threshold-based tagging -
        // dispatching it is this controller's own job precisely because there's no Magento-core
        // event for "the tracking endpoint was called" to observe instead.
        if ($eventType === 'exit_intent') {
            $this->eventManager->dispatch('ordo_visitor_exit_intent', ['visitor_id' => $visitorId]);
        }

        // search_no_results (docs/adr/0076) is also a live trigger, same reasoning as exit_intent
        // above - but unlike that one, a search is meaningful for a logged-in customer too, so
        // customer_id rides along whenever this request is authenticated.
        if ($eventType === 'search_no_results') {
            $this->eventManager->dispatch('ordo_visitor_search_no_results', [
                'visitor_id' => $visitorId,
                'customer_id' => $customerId,
            ]);
        }

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
