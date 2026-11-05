<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * Regenerates every registered product feed format's cached body, walking the catalog ONCE per
 * store rather than once per (format, store) pair — see ADR 0022. Shared by Cron\RefreshProductFeed
 * (its own schedule) and Controller\Adminhtml\ProductFeed\RefreshNow (on-demand), the same "one
 * place owns the real logic, both callers just report its result their own way" split
 * Model\ProductFeed\ProductFeedCacheWriter already uses for the write side.
 *
 * For each store: every generator enabled for it gets startFeed($storeId), then
 * CatalogFeedProductFetcher::fetchByPage($storeId) is walked exactly once, offering each product
 * to every still-active generator's addProduct(), then each gets finishFeed(). A generator that
 * throws during startFeed()/addProduct() is dropped out of that store's batch immediately (and
 * reported failed) without disturbing the shared walk for the generators still active — the same
 * "one store's or one format's failure doesn't affect another's cached feed" guarantee the
 * previous per-(format, store) design had.
 */
class ProductFeedRefresher
{
    public function __construct(
        private readonly FeedGeneratorPool $feedGeneratorPool,
        private readonly CatalogFeedProductFetcher $productFetcher,
        private readonly ProductFeedCacheWriter $productFeedCacheWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{generated: int, skipped: int, failed: int, totalProducts: int, errors: array<int, string>}
     */
    public function refreshAll(): array
    {
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $totalProducts = 0;
        $errors = [];

        $generators = $this->feedGeneratorPool->getAll();

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            /** @var array<string, FeedGeneratorInterface> $activeGenerators keyed by feed code so
             *  a generator that fails mid-walk can be dropped without disturbing iteration over
             *  the rest. */
            $activeGenerators = [];
            foreach ($generators as $generator) {
                if (!$generator->isEnabled($storeId)) {
                    $skipped++;
                    continue;
                }

                try {
                    $generator->startFeed($storeId);
                    $activeGenerators[$generator->getFeedCode()] = $generator;
                } catch (\Throwable $e) {
                    $this->reportFailure($generator, $store, $storeId, $e, $errors);
                    $failed++;
                }
            }

            if ($activeGenerators === []) {
                continue;
            }

            foreach ($this->productFetcher->fetchByPage($storeId) as $product) {
                foreach ($activeGenerators as $feedCode => $generator) {
                    try {
                        $generator->addProduct($product);
                    } catch (\Throwable $e) {
                        $this->reportFailure($generator, $store, $storeId, $e, $errors);
                        $failed++;
                        unset($activeGenerators[$feedCode]);
                    }
                }
            }

            foreach ($activeGenerators as $generator) {
                try {
                    $result = $generator->finishFeed();
                    $this->productFeedCacheWriter->writeSuccess(
                        $generator->getFeedCode(),
                        $storeId,
                        $result['content'],
                        $result['productCount']
                    );
                    $totalProducts += $result['productCount'];
                    $generated++;
                } catch (\Throwable $e) {
                    $this->reportFailure($generator, $store, $storeId, $e, $errors);
                    $failed++;
                }
            }
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
            'totalProducts' => $totalProducts,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, string> $errors
     */
    private function reportFailure(
        FeedGeneratorInterface $generator,
        \Magento\Store\Api\Data\StoreInterface $store,
        int $storeId,
        \Throwable $e,
        array &$errors
    ): void {
        $this->logger->error(sprintf(
            'Ordo_Automation: "%s" feed generation failed for store #%d: %s',
            $generator->getFeedCode(),
            $storeId,
            $e->getMessage()
        ));
        $this->productFeedCacheWriter->writeError($generator->getFeedCode(), $storeId, $e->getMessage());
        $errors[] = sprintf('%s (%s): %s', $store->getName(), $generator->getFeedCode(), $e->getMessage());
    }
}
