<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\ResourceModel\ProductFeedRunLog as ProductFeedRunLogResource;

/**
 * The one place ordo_product_feed_cache is written — shared by Cron\RefreshProductFeed (its own
 * schedule) and Controller\Adminhtml\ProductFeed\RefreshNow (on-demand), same reasoning as
 * Sms\MessageLogWriter being shared between SendSms and the delivery-status webhook.
 *
 * Also appends one row to ordo_product_feed_run_log per call (ProductFeedRunLogFactory), the
 * history the new admin health grid reads — the cache table itself only ever holds the latest
 * attempt per (feed_code, store_id), so a run of transient failures later fixed would otherwise
 * leave no trace once a success overwrites it.
 */
class ProductFeedCacheWriter
{
    private const string FEED_CODE = 'google_merchant';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductFeedRunLogFactory $runLogFactory,
        private readonly ProductFeedRunLogResource $runLogResource
    ) {
    }

    public function writeSuccess(int $storeId, string $xml, int $productCount): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_product_feed_cache');

        // ON DUPLICATE KEY UPDATE has no equivalent in Magento's query builder API; parameters
        // are still bound below, not interpolated (same pattern as RssFetcher::writeSuccess()).
        $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT INTO ' . $connection->quoteIdentifier($table)
            . ' (feed_code, store_id, xml, product_count, generated_at, generation_error) VALUES (?, ?, ?, ?, NOW(), NULL) '
            . 'ON DUPLICATE KEY UPDATE xml = VALUES(xml), product_count = VALUES(product_count), '
            . 'generated_at = VALUES(generated_at), generation_error = NULL',
            [self::FEED_CODE, $storeId, $xml, $productCount]
        );

        $this->appendRunLog($storeId, ProductFeedRunLog::STATUS_SUCCESS, $productCount, null);
    }

    public function writeError(int $storeId, string $message): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_product_feed_cache');

        $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT INTO ' . $connection->quoteIdentifier($table)
            . ' (feed_code, store_id, xml, product_count, generation_error) VALUES (?, ?, \'\', 0, ?) '
            . 'ON DUPLICATE KEY UPDATE generation_error = VALUES(generation_error)',
            [self::FEED_CODE, $storeId, substr($message, 0, 255)]
        );

        $this->appendRunLog($storeId, ProductFeedRunLog::STATUS_ERROR, 0, substr($message, 0, 255));
    }

    /**
     * Swallows its own failure - a DB hiccup persisting this history row must never crash the
     * caller mid-refresh, same reasoning as CronRunLogger::persist().
     */
    private function appendRunLog(int $storeId, string $status, int $productCount, ?string $message): void
    {
        try {
            $entry = $this->runLogFactory->create();
            $entry->setFeedCode(self::FEED_CODE);
            $entry->setStoreId($storeId);
            $entry->setStatus($status);
            $entry->setProductCount($productCount);
            $entry->setMessage($message);
            $this->runLogResource->save($entry);
        } catch (\Throwable) {
            // Best-effort only - see class doc.
        }
    }
}
