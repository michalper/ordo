<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\PendingPopupFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Params: {"headline": "...", "body": "...", "cta_label": "...", "cta_url": "..."}. Unlike every
 * other action, this doesn't send anything itself — it queues a row in ordo_pending_popup that
 * Controller\Track\Poll hands out the next time the target's browser polls
 * (view/frontend/web/js/tracker.js), because there is no synchronous way to push something onto
 * a page from inside a campaign dispatch.
 *
 * Targets whichever identifier the triggering context actually has: context["customer_id"] for
 * customer-only triggers (order_placed, tag_added, ...), context["visitor_id"] for the anonymous
 * visitor_tag_added trigger. At least one must be present, or there is no browser to eventually
 * deliver this to and the action is a no-op (logged, not thrown — same fail-closed pattern as
 * every other action here).
 *
 * Frequency-capped: if the target already received a popup within the configured window
 * (Config::getPopupFrequencyCapHours(), default 24h, 0 disables it), this is a silent no-op —
 * not logged as an error, since skipping is the intended behavior, not a failure.
 */
class ShowPopup implements ActionInterface
{
    public function __construct(
        private readonly PendingPopupFactory $pendingPopupFactory,
        private readonly PendingPopupResource $pendingPopupResource,
        private readonly PendingPopupCollectionFactory $pendingPopupCollectionFactory,
        private readonly Config $config,
        private readonly DateTime $dateTime,
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
                'Ordo_Automation: popup action has no customer_id or visitor_id in context to target.'
            );
            return;
        }

        if ($headline === '') {
            $this->logger->error('Ordo_Automation: popup action is missing a headline.');
            return;
        }

        $capHours = $this->config->getPopupFrequencyCapHours();
        if ($capHours > 0) {
            $since = date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $capHours * 3600);
            $recentlyShown = $this->pendingPopupCollectionFactory->create()
                ->targetHasRecentlyReceivedPopup($target->customerId, $target->visitorId, $since);

            if ($recentlyShown) {
                return;
            }
        }

        $popup = $this->pendingPopupFactory->create();
        $popup->setCustomerId($target->customerId);
        $popup->setVisitorId($target->visitorId);
        $popup->setHeadline($headline);
        $popup->setBody($this->contextTargetResolver->nullableString($params['body'] ?? null));
        $popup->setCtaLabel($this->contextTargetResolver->nullableString($params['cta_label'] ?? null));
        $popup->setCtaUrl($this->contextTargetResolver->nullableString($params['cta_url'] ?? null));

        $this->pendingPopupResource->save($popup);
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
