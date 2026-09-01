<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\PendingPopupFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;
use Psr\Log\LoggerInterface;

/**
 * Params: {"headline": "...", "body": "...", "cta_label": "...", "cta_url": "..."}. Unlike every
 * other action, this doesn't send anything itself — it queues a row in ordo_pending_popup that
 * Controller\Track\Popup hands out the next time the target's browser polls
 * (view/frontend/web/js/tracker.js), because there is no synchronous way to push something onto
 * a page from inside a campaign dispatch.
 *
 * Targets whichever identifier the triggering context actually has: context["customer_id"] for
 * customer-only triggers (order_placed, tag_added, ...), context["visitor_id"] for the anonymous
 * visitor_tag_added trigger. At least one must be present, or there is no browser to eventually
 * deliver this to and the action is a no-op (logged, not thrown — same fail-closed pattern as
 * every other action here).
 */
class ShowPopup implements ActionInterface
{
    public function __construct(
        private readonly PendingPopupFactory $pendingPopupFactory,
        private readonly PendingPopupResource $pendingPopupResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = isset($context['customer_id']) ? (int) $context['customer_id'] : null;
        $customerId = ($customerId !== null && $customerId > 0) ? $customerId : null;

        $visitorId = isset($context['visitor_id']) ? (string) $context['visitor_id'] : null;
        $visitorId = ($visitorId !== null && $visitorId !== '') ? $visitorId : null;

        $headline = trim((string) ($params['headline'] ?? ''));

        if ($customerId === null && $visitorId === null) {
            $this->logger->error('Ordo_Automation: popup action has no customer_id or visitor_id in context to target.');
            return;
        }

        if ($headline === '') {
            $this->logger->error('Ordo_Automation: popup action is missing a headline.');
            return;
        }

        $popup = $this->pendingPopupFactory->create();
        $popup->setCustomerId($customerId);
        $popup->setVisitorId($visitorId);
        $popup->setHeadline($headline);
        $popup->setBody($this->nullableString($params['body'] ?? null));
        $popup->setCtaLabel($this->nullableString($params['cta_label'] ?? null));
        $popup->setCtaUrl($this->nullableString($params['cta_url'] ?? null));

        $this->pendingPopupResource->save($popup);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
