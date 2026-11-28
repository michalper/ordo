<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * An ongoing earn/redeem points balance - distinct from Model\LoyaltyTierCalculator, which is
 * pure bronze/silver/gold tier derivation from spend with no points, no table, and no redemption
 * at all. A customer's real balance is always SUM(points_delta) over an append-only ledger
 * (ordo_loyalty_points_ledger), never a mutated running total - same shape as
 * Model\CampaignOutcomeLogger's own ledger. See docs/adr/0044.
 */
class LoyaltyPointsManager
{
    public const string TYPE_EARN = 'earn';
    public const string TYPE_REDEEM = 'redeem';
    public const string TYPE_EXPIRE = 'expire';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly CouponGenerator $couponGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getBalance(int $customerId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_loyalty_points_ledger');

        $sum = $connection->fetchOne(
            $connection->select()
                ->from($table, ['SUM(points_delta)'])
                ->where('customer_id = ?', $customerId)
        );

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * Idempotent per order - sales_order_save_after (the event Observer\EarnLoyaltyPointsForOrder
     * listens to, see that class's own docblock for why not sales_order_place_after) can fire more
     * than once across an order's lifetime, so this checks for an existing earn row for the same
     * order_id before inserting a new one rather than relying on the caller to only call it once.
     *
     * That check-then-insert is itself only safe under a lock: two overlapping saves of the same
     * order (a retried place-order call, or an admin edit racing a webhook resave) could both pass
     * hasEarnedForOrder() before either finishes insertRow(), crediting the customer twice for one
     * order. A MySQL named lock, scoped per order, serializes the whole check+insert sequence - the
     * same pattern redeem() already uses for its own check-then-insert race, see that method's own
     * comment.
     */
    public function earnForOrder(int $customerId, int $orderId, float $orderTotal): void
    {
        if (!$this->config->isLoyaltyPointsEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $lockName = 'ordo_loyalty_earn_order_' . $orderId;

        // Fail closed (never credit) rather than risk two concurrent saves of the same order both
        // earning points - a missed earn can be noticed and corrected; a double-credit is real
        // money handed out twice.
        if (!(bool) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName])) {
            $this->logger->error(sprintf(
                'Ordo_Automation: could not acquire the earn lock for order #%d - skipping points earn for this save.',
                $orderId
            ));
            return;
        }

        try {
            if ($this->hasEarnedForOrder($orderId)) {
                return;
            }

            $points = (int) floor($orderTotal * $this->config->getLoyaltyPointsPerDollar());
            if ($points <= 0) {
                return;
            }

            $this->insertRow($customerId, self::TYPE_EARN, $points, $orderId);
        } finally {
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            $connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * @throws LocalizedException if the feature is disabled/unconfigured, or the customer's
     *   balance is below the configured redemption cost - caught and reported as an ordinary
     *   {ok: false, reason} business outcome by both the REST and GraphQL transports, not a
     *   hard error.
     */
    public function redeem(int $customerId): string
    {
        if (!$this->config->isLoyaltyPointsEnabled()) {
            throw new LocalizedException(__('Loyalty points are not enabled.'));
        }

        $cost = $this->config->getLoyaltyPointsRedemptionCost();
        $ruleId = $this->config->getLoyaltyPointsRedemptionRuleId();
        if ($cost <= 0 || $ruleId <= 0) {
            throw new LocalizedException(__('Loyalty points redemption is not configured.'));
        }

        $connection = $this->resourceConnection->getConnection();
        $lockName = 'ordo_loyalty_redeem_customer_' . $customerId;

        // The ledger is append-only with no single per-customer row a transaction could lock, so
        // a plain balance-check-then-insert has a real race: two near-simultaneous selfRedeem()
        // calls (double-click, or a client retry on a flaky request) could both read the same
        // balance, both pass the "enough points" check below, and both insert a redeem row,
        // letting the balance go negative for one redemption's worth of cost. A MySQL named lock
        // serializes the whole check+insert sequence per customer instead - the same pattern
        // Model\LeadRouting\LeadAssigner uses for its own read-then-write race. 5s is generous for
        // a fast read+insert pair for a single customer.
        if (!(bool) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName])) {
            throw new LocalizedException(__('Could not process this redemption right now - please try again.'));
        }

        try {
            if ($this->getBalance($customerId) < $cost) {
                throw new LocalizedException(__('Not enough points to redeem.'));
            }

            $couponCode = $this->couponGenerator->generate($ruleId, 'LOYALTY');
            $this->insertRow($customerId, self::TYPE_REDEEM, -$cost, null, $couponCode);

            return $couponCode;
        } finally {
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            $connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * Ages out earn rows older than $expiryDays, oldest first, each expiring at most its own
     * points - capped at the customer's current balance, so a redemption that already consumed
     * some or all of an old cohort is never double-counted (this can't go negative). A simpler,
     * documented approximation of real FIFO lot tracking - see docs/adr/0044's own Consequences.
     */
    public function expireOldEarnedPoints(int $expiryDays): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_loyalty_points_ledger');
        $cutoff = date('Y-m-d H:i:s', time() - $expiryDays * 86400);

        /** @var array<array{entity_id: int, customer_id: int, points_delta: int, created_at: string}> $rows */
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['entity_id', 'customer_id', 'points_delta', 'created_at'])
                ->where('type = ?', self::TYPE_EARN)
                ->where('expired_at IS NULL')
                ->where('created_at <= ?', $cutoff)
                ->order('created_at ASC')
        );

        // One balance query per unique customer up front, instead of one per row - rows are
        // processed oldest-first per customer and each expiry only reduces that same customer's
        // running balance, so tracking it in memory as rows are processed is equivalent to
        // re-querying SUM(points_delta) after every single row, without the N+1 cost on a cron
        // that can process many old rows per customer in one run.
        $customerIds = array_unique(array_map(static fn (array $row): int => (int) $row['customer_id'], $rows));
        $balances = [];
        foreach ($customerIds as $customerId) {
            $balances[$customerId] = $this->getBalance($customerId);
        }

        foreach ($rows as $row) {
            $customerId = (int) $row['customer_id'];
            $balance = $balances[$customerId];

            if ($balance > 0) {
                $toExpire = min((int) $row['points_delta'], $balance);
                $this->insertRow($customerId, self::TYPE_EXPIRE, -$toExpire, null, null, sprintf(
                    'Points earned on %s expired.',
                    $row['created_at']
                ));
                $balances[$customerId] -= $toExpire;
            }

            $connection->update($table, ['expired_at' => date('Y-m-d H:i:s')], ['entity_id = ?' => $row['entity_id']]);
        }
    }

    /**
     * A plain, order-less earn row - unlike earnForOrder(), there's no order_id to key an
     * idempotency check against, so this is NOT idempotent: a campaign action calling this is
     * responsible for its own frequency cap (FrequencyCapManager, or the trigger's own
     * once-per-occurrence nature) if "award it twice" is a real risk for how it's wired. $reason
     * is stored only via the same log line insertRow() already writes for expireOldEarnedPoints(),
     * not a new column - this is a notable event worth a log line, not data the ledger itself
     * needs to answer any question (unlike order_id, nothing reads a bonus's reason back later).
     */
    public function awardBonus(int $customerId, int $points, string $reason): void
    {
        if (!$this->config->isLoyaltyPointsEnabled() || $points <= 0) {
            return;
        }

        $this->insertRow($customerId, self::TYPE_EARN, $points, null, null, $reason);
    }

    private function hasEarnedForOrder(int $orderId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_loyalty_points_ledger');

        $count = $connection->fetchOne(
            $connection->select()
                ->from($table, ['COUNT(*)'])
                ->where('order_id = ?', $orderId)
                ->where('type = ?', self::TYPE_EARN)
        );

        return (int) $count > 0;
    }

    private function insertRow(
        int $customerId,
        string $type,
        int $pointsDelta,
        ?int $orderId = null,
        ?string $couponCode = null,
        ?string $reason = null
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('ordo_loyalty_points_ledger'), [
            'customer_id' => $customerId,
            'type' => $type,
            'points_delta' => $pointsDelta,
            'order_id' => $orderId,
            'coupon_code' => $couponCode,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($reason !== null) {
            $this->logger->info(sprintf('Ordo_Automation: loyalty points ledger (%s): %s', $type, $reason));
        }
    }
}
