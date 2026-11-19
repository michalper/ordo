<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Direct-DB read/corrupt access to ordo_price_watch_subscription for
 * AdminPriceWatchSubscriptionIdempotencyTest - PriceWatchSubscriptionManager::register()'s own
 * "refreshes captured price/stock and clears notified_at on re-registration" claim needs a row
 * already in a stale/notified state to prove the refresh actually happened, and there is no
 * MFTF-reachable way to fast-forward a subscription into that state (it's normally only set by
 * a real scan cron after a real price change) - same "corrupt directly, then verify the fix"
 * technique as CampaignActionCorruptorHelper for its own tables.
 */
class PriceWatchTestHelper extends Helper
{
    use DbConnectionTrait;

    /**
     * Sets last_known_price to an obviously-wrong sentinel and notified_at to "already
     * notified" for the single guest (customer_id IS NULL) subscription matching
     * product_id + watch_type - register()'s own re-registration behavior is what should undo
     * both.
     */
    public function corruptGuestSubscription(
        int $productId,
        string $watchType,
        string $priceSentinel
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'UPDATE ordo_price_watch_subscription '
            . 'SET last_known_price = :price, notified_at = UTC_TIMESTAMP() '
            . 'WHERE product_id = :product_id AND watch_type = :watch_type AND customer_id IS NULL'
        );
        $statement->execute(['price' => $priceSentinel, 'product_id' => $productId, 'watch_type' => $watchType]);
    }

    public function countGuestSubscriptions(
        int $productId,
        string $watchType
    ): int {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM ordo_price_watch_subscription '
            . 'WHERE product_id = :product_id AND watch_type = :watch_type AND customer_id IS NULL'
        );
        $statement->execute(['product_id' => $productId, 'watch_type' => $watchType]);

        return (int) $statement->fetchColumn();
    }

    public function isNotifiedAtNullForGuestSubscription(
        int $productId,
        string $watchType
    ): bool {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT notified_at FROM ordo_price_watch_subscription '
            . 'WHERE product_id = :product_id AND watch_type = :watch_type AND customer_id IS NULL'
        );
        $statement->execute(['product_id' => $productId, 'watch_type' => $watchType]);

        return $statement->fetchColumn() === null;
    }

    public function getLastKnownPriceForGuestSubscription(
        int $productId,
        string $watchType
    ): string {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT last_known_price FROM ordo_price_watch_subscription '
            . 'WHERE product_id = :product_id AND watch_type = :watch_type AND customer_id IS NULL'
        );
        $statement->execute(['product_id' => $productId, 'watch_type' => $watchType]);

        return (string) $statement->fetchColumn();
    }

    public function getGuestEmailForGuestSubscription(
        int $productId,
        string $watchType
    ): string {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT guest_email FROM ordo_price_watch_subscription '
            . 'WHERE product_id = :product_id AND watch_type = :watch_type AND customer_id IS NULL'
        );
        $statement->execute(['product_id' => $productId, 'watch_type' => $watchType]);

        return (string) $statement->fetchColumn();
    }
}
