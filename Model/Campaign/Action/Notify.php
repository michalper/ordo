<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\NotificationFactory;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;
use Psr\Log\LoggerInterface;

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
    public function __construct(
        private readonly NotificationFactory $notificationFactory,
        private readonly NotificationResource $notificationResource,
        private readonly ContextTargetResolver $contextTargetResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $target = $this->contextTargetResolver->resolveCustomerOrVisitor($context);
        $headline = trim((string) ($params['headline'] ?? ''));

        if ($target->isEmpty()) {
            $this->logger->error(
                'Ordo_Automation: notify action has no customer_id or visitor_id in context to target.'
            );
            return;
        }

        if ($headline === '') {
            $this->logger->error('Ordo_Automation: notify action is missing a headline.');
            return;
        }

        $notification = $this->notificationFactory->create();
        $notification->setCustomerId($target->customerId);
        $notification->setVisitorId($target->visitorId);
        $notification->setHeadline($headline);
        $notification->setBody($this->contextTargetResolver->nullableString($params['body'] ?? null));
        $notification->setCtaLabel($this->contextTargetResolver->nullableString($params['cta_label'] ?? null));
        $notification->setCtaUrl($this->contextTargetResolver->nullableString($params['cta_url'] ?? null));

        $this->notificationResource->save($notification);
    }
}
