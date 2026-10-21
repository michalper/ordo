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
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Model\Track\DeliveryClaimer;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated endpoint the frontend JS tracker polls (view/frontend/web/js/
 * tracker.js), same trust model as Controller\Track\Event — no CSRF token, callable by an
 * anonymous visitor with no session/form key yet.
 *
 * Claims (sets delivered_at) the moment a row is handed out, not before — the same
 * claim-before-use pattern as Cron\RunScheduledCampaignActions — so two near-simultaneous polls
 * (e.g. two open tabs) can never both receive the same popup. See Model\Track\DeliveryClaimer.
 */
class Popup extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PendingPopupCollectionFactory $pendingPopupCollectionFactory,
        private readonly DeliveryClaimer $deliveryClaimer,
        private readonly VisitorIdentityResolver $visitorIdentityResolver,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isPopupEnabled()) {
            return $result->setData(['popup' => null]);
        }

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        if ($this->visitorIdentityResolver->hasNoIdentity($visitorId)) {
            return $result->setData(['popup' => null]);
        }

        $now = date('Y-m-d H:i:s');
        $collection = $this->pendingPopupCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);
        // A handful of candidates, not just one — if two near-simultaneous polls both raced to
        // claim the first candidate, the loser must fall through to the next one instead of
        // coming back empty despite a popup genuinely being available.
        $collection->setPageSize(5);

        /** @var PendingPopup|null $popup */
        $popup = $this->deliveryClaimer->claimFirst($collection, 'ordo_pending_popup', $now);
        if ($popup !== null) {
            return $result->setData([
                'popup' => [
                    'headline' => $popup->getHeadline(),
                    'body' => $popup->getBody(),
                    'cta_label' => $popup->getCtaLabel(),
                    'cta_url' => $popup->getCtaUrl(),
                ],
            ]);
        }

        return $result->setData(['popup' => null]);
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
