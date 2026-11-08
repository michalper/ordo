<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\Track\TrackerPollReader;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated endpoint the frontend JS tracker polls (view/frontend/web/js/tracker.js)
 * — merges what used to be three separate polled endpoints (Controller\Track\{Popup,Notification,
 * Survey}) into one, so an open tab makes one request per tick instead of three on three
 * independent timers, each a full Magento bootstrap. See ADR 0024.
 *
 * Each concern keeps its own pre-merge semantics exactly: popup/survey are claim-once
 * (Model\Track\DeliveryClaimer, delivered_at) with the same 5-candidate claim-race fallback as
 * before; notifications are NOT claimed/consumed here — every unread, unexpired row is returned on
 * every poll, same as the standalone controller this replaces (dismissal stays the separate
 * Controller\Track\DismissNotification action). A disabled concern's key is simply omitted from
 * the response rather than present-but-null/empty, since the merged response has no single
 * top-level "enabled" flag the way each old controller's own early-return did.
 *
 * The actual popup/notification/survey resolution lives in Model\Track\TrackerPollReader, shared
 * with Model\GraphQl\Resolver\TrackerPoll (see ADR 0032/0033) — this controller's only own job is
 * resolving identity the REST/session way and wrapping the result as a JSON response.
 */
class Poll extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TrackerPollReader $trackerPollReader,
        private readonly VisitorIdentityResolver $visitorIdentityResolver
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        return $result->setData($this->trackerPollReader->resolve($customerId, $visitorId));
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
