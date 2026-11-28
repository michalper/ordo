<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\LoyaltyPointsManager;

/**
 * `Cron\ExpireLoyaltyPoints` silently ages out old earn rows with zero customer-facing notice
 * beforehand - the one standard loyalty-program retention lever ("you're about to lose points,
 * come spend them") this module's points feature didn't have, despite every *other* "something
 * is about to expire" signal in this codebase (offer expiry, credit limit, wishlist/cart/browse
 * abandonment) already following the same scan->claim->dispatch shape. This closes that gap.
 *
 * Same shape as `Cron\ScanReviewRequestDue` (not one of the shared abstract bases - see that
 * class's own docblock reference to docs/adr/0065 for why a structurally similar but not
 * identical cron is deliberately left out of those abstractions rather than forced into one):
 * dispatches at most once per `ordo_loyalty_points_ledger` earn row, claimed by that row's own
 * `entity_id` in `ordo_loyalty_points_expiring_reminder_log`.
 *
 * A row is "due" once its age falls inside the warning window: not yet old enough for
 * `LoyaltyPointsManager::expireOldEarnedPoints($expiryDays)` to have expired it
 * (`created_at > now - expiryDays days`), but within `warningDays` of that cutoff
 * (`created_at <= now - (expiryDays - warningDays) days`). Deliberately does NOT check the
 * customer's current balance the way `expireOldEarnedPoints()` itself caps expiry at it - this is
 * the same simpler, documented FIFO approximation ADR 0044 already accepts for the expiry side
 * itself (a row whose points were already spent via a later redemption may still trigger a
 * warning here, same as it may still get "expired" - zero actual points at that point - by the
 * other cron). `at_risk_points` in the dispatched context is this ONE row's own `points_delta`,
 * not a per-customer total across every row currently in the warning window - a customer with
 * more than one row due in the same scan dispatches once per row, same reasoning
 * `TRIGGER_WISHLIST_ABANDONED` dispatches once per wishlist item rather than once per customer.
 *
 * @phpstan-type LoyaltyPointsExpiringRow array{
 *     ledger_id: int|string,
 *     customer_id: int|string,
 *     points_delta: int|string
 * }
 */
class SendLoyaltyPointsExpiringReminders
{
    private const string LOG_TABLE = 'ordo_loyalty_points_expiring_reminder_log';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly CronRunLogger $cronRunLogger,
        private readonly ReminderLogStore $reminderLogStore
    ) {
    }

    public function execute(): void
    {
        $expiryDays = $this->config->getLoyaltyPointsExpiryDays();
        $warningDays = $this->config->getLoyaltyPointsExpiryWarningDays();

        if (!$this->config->isLoyaltyPointsEnabled() || $expiryDays <= 0 || $warningDays <= 0) {
            return;
        }

        $expiryCutoff = date('Y-m-d H:i:s', (int) strtotime("-{$expiryDays} days"));
        $warningCutoff = date('Y-m-d H:i:s', (int) strtotime('-' . ($expiryDays - $warningDays) . ' days'));

        $connection = $this->resourceConnection->getConnection();
        $ledgerTable = $this->resourceConnection->getTableName('ordo_loyalty_points_ledger');
        $logTable = $this->resourceConnection->getTableName(self::LOG_TABLE);

        $select = $connection->select()
            ->from(['l' => $ledgerTable], ['ledger_id' => 'l.entity_id', 'l.customer_id', 'l.points_delta'])
            ->joinLeft(['r' => $logTable], 'r.ledger_id = l.entity_id', [])
            ->where('l.type = ?', LoyaltyPointsManager::TYPE_EARN)
            ->where('l.expired_at IS NULL')
            ->where('l.created_at > ?', $expiryCutoff)
            ->where('l.created_at <= ?', $warningCutoff)
            ->where('r.entity_id IS NULL');

        /** @var array<int, LoyaltyPointsExpiringRow> $rows */
        $rows = $connection->fetchAll($select);

        $dispatched = 0;
        foreach ($rows as $row) {
            $ledgerId = (int) $row['ledger_id'];
            $customerId = (int) $row['customer_id'];
            $atRiskPoints = (int) $row['points_delta'];

            try {
                // Claim (log) BEFORE dispatching, not after - a crash between a successful
                // dispatch and the log write must never cause a duplicate trigger on the next
                // tick. If the dispatch itself then fails, the claim is rolled back so this row
                // is retried next run. claim() (not a plain insert()) re-checks "already logged
                // for this row" atomically - two overlapping runs can both pass the select's own
                // r.entity_id IS NULL filter before either inserts, so the select alone is only a
                // pre-filter, not the actual guard against a double-dispatch.
                $claimed = $this->reminderLogStore->claimAndRun(
                    self::LOG_TABLE,
                    ['ledger_id = ?' => $ledgerId],
                    [
                        'ledger_id' => $ledgerId,
                        'customer_id' => $customerId,
                        'dispatched_at' => date('Y-m-d H:i:s'),
                    ],
                    function () use ($customerId, $atRiskPoints): void {
                        $this->campaignDispatcher->dispatch(
                            CampaignTriggerInterface::TRIGGER_LOYALTY_POINTS_EXPIRING,
                            ['customer_id' => $customerId, 'at_risk_points' => $atRiskPoints]
                        );
                    }
                );
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('dispatch loyalty_points_expiring trigger for ledger row #%d', $ledgerId),
                    $e
                );
                continue;
            }

            if ($claimed) {
                $dispatched++;
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d loyalty points expiring triggers', $dispatched));
    }
}
