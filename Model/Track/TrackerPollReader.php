<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Track;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;

/**
 * The merged popup/notifications/survey poll response (see ADR 0024), shared by
 * Controller\Track\Poll (REST/session-based storefront) and Model\GraphQl\Resolver\TrackerPoll
 * (GraphQL/customer-token storefront, see ADR 0032) — both transports resolve a caller's identity
 * their own way, but everything past "here is a customer id and/or visitor id" is identical, so
 * it lives here once rather than twice.
 */
class TrackerPollReader
{
    public function __construct(
        private readonly PendingPopupCollectionFactory $pendingPopupCollectionFactory,
        private readonly NotificationCollectionFactory $notificationCollectionFactory,
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly DeliveryClaimer $deliveryClaimer,
        private readonly Config $config
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(?int $customerId, string $visitorId): array
    {
        $hasIdentity = $customerId !== null || $visitorId !== '';
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

        return $data;
    }

    /**
     * @return array{
     *     headline: string, body: ?string, cta_label: ?string, cta_url: ?string,
     *     reward_label: ?string, coupon_code: ?string
     * }|null
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
            // Both null for an ordinary static popup (no reward_tiers on the action that queued
            // this row) — see Model\Campaign\Action\ShowPopup's own docblock.
            'reward_label' => $popup->getRewardLabel(),
            'coupon_code' => $popup->getCouponCode(),
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
            $notificationId = $notification->getId();
            $notifications[] = [
                'id' => is_numeric($notificationId) ? (int) $notificationId : 0,
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

        $promptId = $prompt->getId();

        return [
            'id' => is_numeric($promptId) ? (int) $promptId : 0,
            'question' => $prompt->getQuestion(),
        ];
    }
}
