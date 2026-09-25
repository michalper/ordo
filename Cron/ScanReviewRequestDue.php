<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;

/**
 * Finds registered customers' completed orders (`sales_order.status = 'complete'`, `customer_id`
 * set - a guest order has no campaign target, same scoping SendBrowseAbandonmentReminders
 * already applies) that turned `review_request_delay_days` old and haven't already had a
 * `review_request_due` trigger dispatched for them (`ordo_review_request_log`, one row per order,
 * unique on order_id), then dispatches that trigger - a store wires whatever action it wants
 * (send_email/generate_coupon/add_points/...) onto it via the campaign builder, same
 * dispatch-a-trigger-with-no-built-in-action shape as browse_abandoned.
 *
 * Deliberately does not check Magento's own `review`/`review_detail` tables for whether the
 * customer already reviewed something from this order - dispatches once per completed order,
 * unconditionally, same "one entity, one dedup key" simplicity every other reminder cron in this
 * module uses. A store that wants to skip customers who already reviewed can do so with a
 * campaign condition on top of this trigger.
 *
 * @phpstan-type ReviewRequestDueRow array{order_id: int|string, customer_id: int|string}
 */
class ScanReviewRequestDue
{
    private const string LOG_TABLE = 'ordo_review_request_log';

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
        if (!$this->config->isReviewRequestEnabled()) {
            return;
        }

        $delayDays = $this->config->getReviewRequestDelayDays();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$delayDays} days"));

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $logTable = $this->resourceConnection->getTableName(self::LOG_TABLE);

        $select = $connection->select()
            ->from(['o' => $orderTable], ['order_id' => 'o.entity_id', 'customer_id' => 'o.customer_id'])
            ->joinLeft(['l' => $logTable], 'l.order_id = o.entity_id', [])
            ->where('o.status = ?', 'complete')
            ->where('o.customer_id IS NOT NULL')
            ->where('o.created_at <= ?', $cutoff)
            ->where('l.entity_id IS NULL');

        /** @var array<int, ReviewRequestDueRow> $rows */
        $rows = $connection->fetchAll($select);

        $dispatched = 0;
        foreach ($rows as $row) {
            $orderId = (int) $row['order_id'];
            $customerId = (int) $row['customer_id'];

            // Claim (log) BEFORE dispatching, not after - a crash between a successful dispatch
            // and the log write must never cause a duplicate trigger on the next tick. If the
            // dispatch itself then fails, the claim is rolled back so this order is retried next
            // run - same reasoning as every other reminder cron in this module.
            $logRow = [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'dispatched_at' => date('Y-m-d H:i:s'),
            ];
            $this->reminderLogStore->insert(self::LOG_TABLE, $logRow);

            try {
                $this->campaignDispatcher->dispatch(CampaignTriggerInterface::TRIGGER_REVIEW_REQUEST_DUE, [
                    'customer_id' => $customerId,
                    'order_id' => $orderId,
                ]);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::LOG_TABLE, $logRow);
                $this->cronRunLogger->logFailure(
                    sprintf('dispatch review_request_due trigger for order #%d', $orderId),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d review request triggers', $dispatched));
    }
}
