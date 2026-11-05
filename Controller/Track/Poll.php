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
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\Track\DeliveryClaimer;
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
 */
class Poll extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PendingPopupCollectionFactory $pendingPopupCollectionFactory,
        private readonly NotificationCollectionFactory $notificationCollectionFactory,
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly DeliveryClaimer $deliveryClaimer,
        private readonly VisitorIdentityResolver $visitorIdentityResolver,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();
        $hasIdentity = !$this->visitorIdentityResolver->hasNoIdentity($visitorId);
        $now = date('Y-m-d H:i:s');

        $data = [];
        if ($this->config->isPopupEnabled()) {
            $data['popup'] = $hasIdentity ? $this->resolvePopup($customerId, $visitorId, $now) : null;
        }
        if ($this->config->isNotificationEnabled()) {
            $data['notifications'] = $hasIdentity ? $this->resolveNotifications($customerId, $visitorId, $now) : [];
        }
        if ($this->config->isNpsSurveyEnabled()) {
            $data['survey'] = $hasIdentity ? $this->resolveSurvey($customerId, $visitorId, $now) : null;
        }

        return $result->setData($data);
    }

    /**
     * @return array{headline: string, body: ?string, cta_label: ?string, cta_url: ?string}|null
     */
    private function resolvePopup(?int $customerId, string $visitorId, string $now): ?array
    {
        $collection = $this->pendingPopupCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);
        // A handful of candidates, not just one — if two near-simultaneous polls both raced to
        // claim the first candidate, the loser must fall through to the next one instead of
        // coming back empty despite a popup genuinely being available.
        $collection->setPageSize(5);

        /** @var PendingPopup|null $popup */
        $popup = $this->deliveryClaimer->claimFirst($collection, 'ordo_pending_popup', $now);
        if ($popup === null) {
            return null;
        }

        return [
            'headline' => $popup->getHeadline(),
            'body' => $popup->getBody(),
            'cta_label' => $popup->getCtaLabel(),
            'cta_url' => $popup->getCtaUrl(),
        ];
    }

    /**
     * @return array<int, array{id: int, headline: string, body: ?string, cta_label: ?string, cta_url: ?string}>
     */
    private function resolveNotifications(?int $customerId, string $visitorId, string $now): array
    {
        $collection = $this->notificationCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);

        $notifications = [];
        foreach ($collection as $notification) {
            /** @var Notification $notification */
            $notifications[] = [
                'id' => (int) $notification->getId(),
                'headline' => $notification->getHeadline(),
                'body' => $notification->getBody(),
                'cta_label' => $notification->getCtaLabel(),
                'cta_url' => $notification->getCtaUrl(),
            ];
        }

        return $notifications;
    }

    /**
     * @return array{id: int, question: string}|null
     */
    private function resolveSurvey(?int $customerId, string $visitorId, string $now): ?array
    {
        $collection = $this->surveyPromptCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);
        // Same claim-race reasoning as resolvePopup() above.
        $collection->setPageSize(5);

        /** @var SurveyPrompt|null $prompt */
        $prompt = $this->deliveryClaimer->claimFirst($collection, 'ordo_survey_prompt', $now);
        if ($prompt === null) {
            return null;
        }

        return [
            'id' => (int) $prompt->getId(),
            'question' => $prompt->getQuestion(),
        ];
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
