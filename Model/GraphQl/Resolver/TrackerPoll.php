<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\Notification;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\Track\DeliveryClaimer;

/**
 * GraphQL counterpart of Controller\Track\Poll - identical {popup, notifications, survey} shape
 * and semantics (claim-once for popup/survey, never-claimed for notifications), just reusing the
 * same collection factories/claimer instead of duplicating the logic. See that controller's own
 * docblock for why the three concerns were merged into one call in the first place (ADR 0024).
 */
class TrackerPoll implements ResolverInterface
{
    public function __construct(
        private readonly PendingPopupCollectionFactory $pendingPopupCollectionFactory,
        private readonly NotificationCollectionFactory $notificationCollectionFactory,
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly DeliveryClaimer $deliveryClaimer,
        private readonly CustomerIdResolver $customerIdResolver,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{visitorId?: ?string}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        /** @var BaseContextInterface $context */
        $customerId = $this->customerIdResolver->resolveCustomerId($context);
        $visitorId = isset($args['visitorId']) && is_string($args['visitorId']) ? $args['visitorId'] : '';
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
     * @return array{headline: string, body: ?string, cta_label: ?string, cta_url: ?string}|null
     */
    private function resolvePopup(?int $customerId, string $visitorId, string $now): ?array
    {
        $collection = $this->pendingPopupCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);
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
}
