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
 * The back-in-stock counterpart to Cron\ScanPriceDropAlerts, same shape: scans
 * ordo_price_watch_subscription rows with watch_type `back_in_stock` and no notified_at yet,
 * loads each watched product's current salable status, and dispatches a `back_in_stock`
 * campaign trigger the first time it transitions from out-of-stock to in-stock relative to the
 * row's own last_known_in_stock — never on every scan while the product simply stays in stock.
 *
 * Guest watches (no customer_id) still get their last_known_in_stock refreshed every run, but
 * never dispatch a campaign trigger, same restriction ScanPriceDropAlerts applies.
 * TODO: a guest-facing notification path is out of scope for this PR, same decision as
 * ScanPriceDropAlerts.
 *
 * @phpstan-type BackInStockRow array{
 *     entity_id: int|string,
 *     customer_id: int|string|null,
 *     product_id: int|string,
 *     last_known_in_stock: int|string|null
 * }
 */
class ScanBackInStockAlerts
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
            ->from($table, ['entity_id', 'customer_id', 'product_id', 'last_known_in_stock'])
            ->where('watch_type = ?', PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK)
            ->where('notified_at IS NULL')
            ->order('entity_id ASC')
            ->limit($batchSize);

        /** @var array<int, BackInStockRow> $rows */
        $rows = $connection->fetchAll($select);

        $dispatched = 0;
        foreach ($rows as $row) {
            try {
                if ($this->processRow($row)) {
                    $dispatched++;
                }
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('scan back in stock watch subscription #%d', (int) $row['entity_id']),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d back in stock triggers', $dispatched));
    }

    /**
     * @param BackInStockRow $row
     * @return bool whether a back_in_stock campaign trigger was actually dispatched for this row
     */
    private function processRow(array $row): bool
    {
        $entityId = (int) $row['entity_id'];
        $productId = (int) $row['product_id'];
        $oldInStock = $row['last_known_in_stock'] !== null ? (bool) $row['last_known_in_stock'] : null;

        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            // Product deleted since this watch was registered - nothing left to compare against.
            return false;
        }

        $newInStock = $product->isSalable();
        $isRealRestock = $oldInStock === false && $newInStock === true;
        $customerId = !empty($row['customer_id']) ? (int) $row['customer_id'] : null;

        if ($isRealRestock && $customerId !== null) {
            // Claim (set notified_at) BEFORE dispatching, not after - see
            // Cron\ScanPriceDropAlerts::processRow() for the same crash-safety reasoning.
            $this->claim($entityId, $newInStock);

            try {
                $this->campaignDispatcher->dispatch(CampaignTriggerInterface::TRIGGER_BACK_IN_STOCK, [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'old_in_stock' => $oldInStock,
                    'new_in_stock' => $newInStock,
                ]);
            } catch (\Throwable $e) {
                $this->unclaim($entityId);
                throw $e;
            }

            return true;
        }

        $this->updateStock($entityId, $newInStock);
        return false;
    }

    private function claim(int $entityId, bool $newInStock): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update(
            $table,
            ['last_known_in_stock' => $newInStock, 'notified_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => $entityId]
        );
    }

    private function unclaim(int $entityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update($table, ['notified_at' => null], ['entity_id = ?' => $entityId]);
    }

    private function updateStock(int $entityId, bool $newInStock): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update($table, ['last_known_in_stock' => $newInStock], ['entity_id = ?' => $entityId]);
    }
}
