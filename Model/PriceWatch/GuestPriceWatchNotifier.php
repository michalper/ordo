<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\PriceWatch;

use Magento\Catalog\Model\Product;
use Ordo\Automation\Model\Cron\ReminderEmailSender;

/**
 * Sends a price-drop/back-in-stock alert directly to a guest's own address, bypassing
 * CampaignDispatcher entirely — every condition/action a `price_drop`/`back_in_stock` campaign can
 * run assumes a real customer_id (see Cron\AbstractPriceWatchScanCron), which a guest watch never
 * has. Reuses ReminderEmailSender (the same suspend/build/send/resume extraction
 * SendWinBackEmails/SendOfferExpiryReminders/SendReorderReminders/SendCreditLimitAlerts/
 * SendSalesRepDigest already share) rather than a sixth copy of that shape, addressed to the
 * email a guest gave at registration (PriceWatchSubscription::getGuestEmail()) instead of a
 * customer's own.
 *
 * Deliberately doesn't catch its own send failure — AbstractPriceWatchScanCron's caller unclaims
 * the row and retries next run on any Throwable, same as a failed campaign dispatch.
 */
class GuestPriceWatchNotifier
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_price_watch_guest_alert';

    public function __construct(
        private readonly ReminderEmailSender $reminderEmailSender
    ) {
    }

    /**
     * @param array<string, mixed> $payload the change-specific fields from
     *   AbstractPriceWatchScanCron::triggerPayload() (e.g. old_price/new_price,
     *   old_in_stock/new_in_stock)
     */
    public function notify(string $email, Product $product, string $watchType, array $payload): void
    {
        $this->reminderEmailSender->send(
            self::XML_PATH_EMAIL_TEMPLATE,
            [
                'product_name' => $product->getName(),
                'product_url' => $product->getProductUrl(),
                'message' => $this->buildMessage($watchType, $payload),
            ],
            $email
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildMessage(string $watchType, array $payload): string
    {
        if ($watchType === PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK) {
            return (string) __('Good news — the product you were watching is back in stock.');
        }

        $newPrice = $payload['new_price'] ?? null;
        $newPriceText = is_scalar($newPrice) ? (string) $newPrice : '';

        return (string) __('Good news — the price dropped to %1.', $newPriceText);
    }
}
