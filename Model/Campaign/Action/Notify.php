<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\NotificationFactory;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"headline": "...", "body": "...", "cta_label": "...", "cta_url": "..."} - same shape
 * as ShowPopup, reusing the same dedicated admin fields (ordo_campaign_form.xml's switcherConfig
 * shows headline/body/cta_label/cta_url for this action type too). The real difference from
 * ShowPopup is entirely server-side: this queues a row in ordo_notification, not
 * ordo_pending_popup, which Controller\Track\Poll returns on EVERY poll (not
 * claimed-and-gone) until the visitor/customer dismisses it or it expires - a persistent,
 * non-modal banner instead of a one-shot popup.
 *
 * Targets whichever identifier the triggering context actually has, same convention as ShowPopup
 * (context["customer_id"] for customer-only triggers, context["visitor_id"] for the anonymous
 * visitor_tag_added trigger). No frequency cap - unlike a popup, a persistent notification isn't
 * at risk of being shown repeatedly (it stays visible until dismissed), so there's nothing to cap.
 */
class Notify implements ActionInterface
{
    private const string ACTION_LABEL = 'notify';

    public function __construct(
        private readonly NotificationFactory $notificationFactory,
        private readonly NotificationResource $notificationResource,
        private readonly ContextTargetResolver $contextTargetResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $resolved = $this->contextTargetResolver
            ->resolveTargetAndRequiredField($context, $params, 'headline', self::ACTION_LABEL);
        if ($resolved === null) {
            return;
        }
        $target = $resolved['target'];
        $headline = $resolved['value'];

        $notification = $this->notificationFactory->create();
        $notification->setCustomerId($target->customerId);
        $notification->setVisitorId($target->visitorId);
        $notification->setHeadline($headline);
        $notification->setBody($this->contextTargetResolver->nullableString($params['body'] ?? null));
        $notification->setCtaLabel($this->contextTargetResolver->nullableString($params['cta_label'] ?? null));
        $notification->setCtaUrl($this->contextTargetResolver->nullableString($params['cta_url'] ?? null));

        try {
            $this->notificationResource->save($notification);
        } catch (Throwable $e) {
            // Same fail-closed convention every other action in this module follows - a save
            // failure here must not propagate out of execute() and abort the rest of this
            // dispatch's own action chain (CampaignDispatcher::runOneAction() calls execute()
            // with no try/catch of its own).
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign %s action failed to save: %s',
                self::ACTION_LABEL,
                $e->getMessage()
            ));
        }
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'headline', 'label' => (string) __('Popup headline')],
            ['name' => 'body', 'label' => (string) __('Popup body')],
            ['name' => 'cta_label', 'label' => (string) __('CTA button label')],
            ['name' => 'cta_url', 'label' => (string) __('CTA button URL')],
        ];
    }
}
