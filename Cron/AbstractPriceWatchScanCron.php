<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Shared scan/claim/dispatch skeleton for the ordo_price_watch_subscription scan crons
 * (ScanPriceDropAlerts, ScanBackInStockAlerts) — both scan the same table for a given watch_type
 * with no notified_at yet, load each watched product, and dispatch a campaign trigger the first
 * time the tracked value genuinely changes for the better relative to the row's own last-known
 * snapshot, never again on every scan while it stays that way. Only what "changed" means (price
 * vs. stock) and the snapshot column differ between the two — everything else (batching, the
 * claim-before-dispatch crash safety, guest watches never dispatching, logging) is identical and
 * lives here once.
 */
abstract class AbstractPriceWatchScanCron
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

    /**
     * @return string the watch_type this cron scans (PriceWatchSubscription::WATCH_TYPE_*).
     */
    abstract protected function watchType(): string;

    /**
     * @return string the row column this cron reads/writes its last-known snapshot into.
     */
    abstract protected function snapshotColumn(): string;

    /**
     * @return string the campaign trigger code to dispatch on a genuine, notifiable change
     *   (CampaignTriggerInterface::TRIGGER_*).
     */
    abstract protected function triggerCode(): string;

    /**
     * @return string a short, human-readable noun phrase for log messages (e.g. "price drop").
     */
    abstract protected function triggerLabel(): string;

    /**
     * Reads the current value to compare against the row's snapshot straight off the product
     * (e.g. its final price, or whether it's currently salable).
     *
     */
    abstract protected function readCurrentValue(ProductInterface $product): mixed;

    /**
     * @param mixed $oldValue the row's raw snapshot value before this scan (null if never set)
     * @param mixed $newValue this scan's freshly read value, as returned by readCurrentValue()
     */
    abstract protected function isNotifiableChange(mixed $oldValue, mixed $newValue): bool;

    /**
     * @return array<string, mixed> the campaign trigger payload's change-specific fields, merged
     *   with customer_id/product_id.
     */
    abstract protected function triggerPayload(mixed $oldValue, mixed $newValue): array;

    public function execute(): void
    {
        if (!$this->config->isPriceWatchEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $batchSize = $this->config->getPriceWatchScanBatchSize();
        $snapshotColumn = $this->snapshotColumn();

        $select = $connection->select()
            ->from($table, ['entity_id', 'customer_id', 'product_id', $snapshotColumn])
            ->where('watch_type = ?', $this->watchType())
            ->where('notified_at IS NULL')
            ->order('entity_id ASC')
            ->limit($batchSize);

        /** @var array<int, array{entity_id: int|string, customer_id: int|string|null, product_id: int|string}> $rows */
        $rows = $connection->fetchAll($select);

        $dispatched = 0;
        foreach ($rows as $row) {
            try {
                if ($this->processRow($row)) {
                    $dispatched++;
                }
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('scan %s watch subscription #%d', $this->triggerLabel(), (int) $row['entity_id']),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d %s triggers', $dispatched, $this->triggerLabel()));
    }

    /**
     * @param array{entity_id: int|string, customer_id: int|string|null, product_id: int|string} $row
     * @return bool whether a campaign trigger was actually dispatched for this row
     */
    private function processRow(array $row): bool
    {
        $entityId = (int) $row['entity_id'];
        $productId = (int) $row['product_id'];
        $snapshotColumn = $this->snapshotColumn();
        $oldValue = $row[$snapshotColumn] ?? null;

        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            // Product deleted since this watch was registered - nothing left to compare against.
            return false;
        }

        $newValue = $this->readCurrentValue($product);
        $isNotifiable = $this->isNotifiableChange($oldValue, $newValue);
        $customerId = !empty($row['customer_id']) ? (int) $row['customer_id'] : null;

        if ($isNotifiable && $customerId !== null) {
            // Claim (set notified_at) BEFORE dispatching, not after - a crash between a
            // successful dispatch and the claim write must never cause a duplicate trigger on
            // the next tick. If the dispatch itself then fails, the claim is rolled back so this
            // row is retried next run - same pattern as every other reminder cron in this module.
            $this->claim($entityId, $newValue);

            try {
                $this->campaignDispatcher->dispatch($this->triggerCode(), array_merge(
                    ['customer_id' => $customerId, 'product_id' => $productId],
                    $this->triggerPayload($oldValue, $newValue)
                ));
            } catch (\Throwable $e) {
                $this->unclaim($entityId);
                throw $e;
            }

            return true;
        }

        // No notifiable change yet, or a guest watch - just refresh the snapshot so the next scan
        // compares against the product's current value, never dispatching for a guest.
        $this->updateSnapshot($entityId, $newValue);
        return false;
    }

    private function claim(int $entityId, mixed $newValue): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update(
            $table,
            [$this->snapshotColumn() => $newValue, 'notified_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => $entityId]
        );
    }

    private function unclaim(int $entityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update($table, ['notified_at' => null], ['entity_id = ?' => $entityId]);
    }

    private function updateSnapshot(int $entityId, mixed $newValue): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update($table, [$this->snapshotColumn() => $newValue], ['entity_id = ?' => $entityId]);
    }
}
