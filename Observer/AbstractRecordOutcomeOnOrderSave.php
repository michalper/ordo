<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\OutcomeLoggerInterface;

/**
 * Shared by RecordCampaignOutcome and RecordTriggerOutcome - see either one's own docblock for
 * why this is on sales_order_save_after, not sales_order_place_after: a fresh (non-reorder)
 * order's entity_id is genuinely unpopulated at place_after time, which silently no-oped both of
 * these every time until this was found and fixed.
 */
abstract class AbstractRecordOutcomeOnOrderSave implements ObserverInterface
{
    public function __construct(
        private readonly OutcomeLoggerInterface $outcomeLogger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId() || !$order->getEntityId()) {
            return;
        }

        $this->outcomeLogger->markActed((int) $order->getCustomerId(), (int) $order->getEntityId());
    }
}
