<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Catalog\Api\Data\ProductInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;

/**
 * The back-in-stock counterpart to Cron\ScanPriceDropAlerts, same shape: scans
 * ordo_price_watch_subscription rows with watch_type `back_in_stock` and no notified_at yet,
 * loads each watched product's current salable status, and dispatches a `back_in_stock`
 * campaign trigger the first time it transitions from out-of-stock to in-stock relative to the
 * row's own last_known_in_stock — never on every scan while the product simply stays in stock.
 *
 * Guest watches (no customer_id) still get their last_known_in_stock refreshed every run; a guest
 * never dispatches a campaign trigger (every condition/action a campaign can run assumes a
 * customer_id), but one that gave an email at registration gets notified directly instead — see
 * AbstractPriceWatchScanCron/GuestPriceWatchNotifier, same as ScanPriceDropAlerts.
 *
 * The shared scan/claim/dispatch mechanics (batching, crash-safe claim-before-dispatch, guest
 * exclusion, logging) live in AbstractPriceWatchScanCron — this class only supplies the
 * stock-specific comparison.
 */
class ScanBackInStockAlerts extends AbstractPriceWatchScanCron
{
    protected function watchType(): string
    {
        return PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK;
    }

    protected function snapshotColumn(): string
    {
        return 'last_known_in_stock';
    }

    protected function triggerCode(): string
    {
        return CampaignTriggerInterface::TRIGGER_BACK_IN_STOCK;
    }

    protected function triggerLabel(): string
    {
        return 'back in stock';
    }

    protected function readCurrentValue(ProductInterface $product): mixed
    {
        return $product->isSalable();
    }

    protected function isNotifiableChange(mixed $oldValue, mixed $newValue): bool
    {
        $oldInStock = $oldValue !== null ? (bool) $oldValue : null;

        return $oldInStock === false && $newValue === true;
    }

    protected function triggerPayload(mixed $oldValue, mixed $newValue): array
    {
        return [
            'old_in_stock' => $oldValue !== null ? (bool) $oldValue : null,
            'new_in_stock' => $newValue,
        ];
    }
}
