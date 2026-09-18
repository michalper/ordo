<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Catalog\Api\Data\ProductInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;

/**
 * Scans ordo_price_watch_subscription rows with watch_type `price_drop` and no notified_at yet
 * (see PriceWatchSubscriptionManager::register(), which clears notified_at whenever a watch is
 * (re-)armed), loads each watched product's current price, and dispatches a `price_drop`
 * campaign trigger the first time the current price is genuinely lower than the row's own
 * last_known_price — a snapshot captured at registration time (or the last time this cron
 * updated it), not the product's original/list price, so a subscription only ever fires once per
 * real drop, never again on every scan while the price stays low.
 *
 * Batched via LIMIT (Helper\Config::getPriceWatchScanBatchSize()), same reasoning as every other
 * scan cron in this module: an unbounded table scan here would grow linearly with how many
 * watches exist, not with how many actually changed since the last run.
 *
 * Guest watches (no customer_id) still get their last_known_price refreshed every run; a guest never
 * dispatches a campaign trigger — every condition/action a campaign can run here assumes a real
 * customer_id, same restriction SendAbandonedCartReminders applies to guest quotes — but one that
 * gave an email at registration gets notified directly instead of through the campaign engine, see
 * AbstractPriceWatchScanCron/GuestPriceWatchNotifier.
 *
 * The shared scan/claim/dispatch mechanics (batching, crash-safe claim-before-dispatch, guest
 * exclusion, logging) live in AbstractPriceWatchScanCron — this class only supplies the
 * price-specific comparison.
 */
class ScanPriceDropAlerts extends AbstractPriceWatchScanCron
{
    protected function watchType(): string
    {
        return PriceWatchSubscription::WATCH_TYPE_PRICE_DROP;
    }

    protected function snapshotColumn(): string
    {
        return 'last_known_price';
    }

    protected function triggerCode(): string
    {
        return CampaignTriggerInterface::TRIGGER_PRICE_DROP;
    }

    protected function triggerLabel(): string
    {
        return 'price drop';
    }

    protected function readCurrentValue(ProductInterface $product): mixed
    {
        return (float) $product->getFinalPrice();
    }

    protected function isNotifiableChange(mixed $oldValue, mixed $newValue): bool
    {
        $oldPrice = $oldValue !== null ? (float) $oldValue : null;

        return $oldPrice !== null && $newValue < $oldPrice;
    }

    protected function triggerPayload(mixed $oldValue, mixed $newValue): array
    {
        return [
            'old_price' => $oldValue !== null ? (float) $oldValue : null,
            'new_price' => $newValue,
        ];
    }
}
