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
     */
    public function earnForOrder(int $customerId, int $orderId, float $orderTotal): void
    {
        if (!$this->config->isLoyaltyPointsEnabled() || $this->hasEarnedForOrder($orderId)) {
            return;
        }

        $points = (int) floor($orderTotal * $this->config->getLoyaltyPointsPerDollar());
        if ($points <= 0) {
            return;
        }

        $this->insertRow($customerId, self::TYPE_EARN, $points, $orderId);
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

        if ($this->getBalance($customerId) < $cost) {
            throw new LocalizedException(__('Not enough points to redeem.'));
        }

        $couponCode = $this->couponGenerator->generate($ruleId, 'LOYALTY');
        $this->insertRow($customerId, self::TYPE_REDEEM, -$cost, null, $couponCode);

        return $couponCode;
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

        foreach ($rows as $row) {
            $customerId = (int) $row['customer_id'];
            $balance = $this->getBalance($customerId);

            if ($balance > 0) {
                $toExpire = min((int) $row['points_delta'], $balance);
                $this->insertRow($customerId, self::TYPE_EXPIRE, -$toExpire, null, null, sprintf(
                    'Points earned on %s expired.',
                    $row['created_at']
                ));
            }

            $connection->update($table, ['expired_at' => date('Y-m-d H:i:s')], ['entity_id = ?' => $row['entity_id']]);
        }
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
