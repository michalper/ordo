<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
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
 * Guest watches (no customer_id) still get their last_known_price refreshed every run, but never
 * dispatch a campaign trigger — every condition/action a campaign can run here assumes a real
 * customer_id, same restriction SendAbandonedCartReminders applies to guest quotes.
 * TODO: a guest-facing notification path (e.g. a captured email address, sent directly rather
 * than through the campaign engine) is a real gap, scoped out of this PR by deliberate decision.
 *
 * @phpstan-type PriceWatchRow array{
 *     entity_id: int|string,
 *     customer_id: int|string|null,
 *     product_id: int|string,
 *     last_known_price: string|float|null
 * }
 */
class ScanPriceDropAlerts
{
    private const string TABLE = 'ordo_price_watch_subscription';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isPriceWatchEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $batchSize = $this->config->getPriceWatchScanBatchSize();

        $select = $connection->select()
            ->from($table, ['entity_id', 'customer_id', 'product_id', 'last_known_price'])
            ->where('watch_type = ?', PriceWatchSubscription::WATCH_TYPE_PRICE_DROP)
            ->where('notified_at IS NULL')
            ->order('entity_id ASC')
            ->limit($batchSize);

        /** @var array<int, PriceWatchRow> $rows */
        $rows = $connection->fetchAll($select);

        $dispatched = 0;
        foreach ($rows as $row) {
            try {
                if ($this->processRow($row)) {
                    $dispatched++;
                }
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('scan price watch subscription #%d', (int) $row['entity_id']),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d price drop triggers', $dispatched));
    }

    /**
     * @param PriceWatchRow $row
     * @return bool whether a price_drop campaign trigger was actually dispatched for this row
     */
    private function processRow(array $row): bool
    {
        $entityId = (int) $row['entity_id'];
        $productId = (int) $row['product_id'];
        $oldPrice = $row['last_known_price'] !== null ? (float) $row['last_known_price'] : null;

        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            // Product deleted since this watch was registered - nothing left to compare against.
            return false;
        }

        $newPrice = (float) $product->getFinalPrice();
        $isRealDrop = $oldPrice !== null && $newPrice < $oldPrice;
        $customerId = !empty($row['customer_id']) ? (int) $row['customer_id'] : null;

        if ($isRealDrop && $customerId !== null) {
            // Claim (set notified_at) BEFORE dispatching, not after - a crash between a
            // successful dispatch and the claim write must never cause a duplicate trigger on
            // the next tick. If the dispatch itself then fails, the claim is rolled back so this
            // row is retried next run - same pattern as every other reminder cron in this module.
            $this->claim($entityId, $newPrice);

            try {
                $this->campaignDispatcher->dispatch(CampaignTriggerInterface::TRIGGER_PRICE_DROP, [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                ]);
            } catch (\Throwable $e) {
                $this->unclaim($entityId);
                throw $e;
            }

            return true;
        }

        // No real drop yet, or a guest watch - just refresh last_known_price so the next scan
        // compares against the product's current price, never dispatching for a guest.
        $this->updatePrice($entityId, $newPrice);
        return false;
    }

    private function claim(int $entityId, float $newPrice): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update(
            $table,
            ['last_known_price' => $newPrice, 'notified_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => $entityId]
        );
    }

    private function unclaim(int $entityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update($table, ['notified_at' => null], ['entity_id = ?' => $entityId]);
    }

    private function updatePrice(int $entityId, float $newPrice): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update($table, ['last_known_price' => $newPrice], ['entity_id = ?' => $entityId]);
    }
}
