<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerStoreResolver;

/**
 * Not "dunning" in the subscription-billing sense other platforms mean it — Magento Open Source
 * has no recurring billing for this module to hook into at all — but the same, already-
 * precedented "cron scan against a claim column" shape every other reminder feature here uses
 * (CLAUDE.md's own architecture note). An order can sit in Magento's own `pending_payment` state
 * (`Order::STATE_PENDING_PAYMENT`) for reasons ranging from a customer who abandoned an online
 * gateway mid-redirect to an offline method (bank transfer) still waiting on manual confirmation
 * of a payment the customer already made — this module has no way to tell those apart from the
 * order state alone, so the email copy (`stalled_payment_reminder.html`) is deliberately hedged
 * ("if you've already paid, no action is needed") rather than presuming the customer forgot to
 * pay. See docs/adr/0047.
 */
class SendStalledPaymentReminders
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_stalled_payment_reminder';
    private const string REMINDER_LOG_TABLE = 'ordo_stalled_payment_reminder_log';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly ReminderEmailSender $emailSender,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly ConsentManager $consentManager,
        private readonly CustomerStoreResolver $customerStoreResolver,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isStalledPaymentReminderEnabled()) {
            return;
        }

        $orders = $this->fetchStalledOrders();
        if ($orders === []) {
            $this->cronRunLogger->logSummary(sprintf('sent %d stalled payment reminder(s)', 0));
            return;
        }

        $customerIds = array_column($orders, 'customer_id');
        $customerMap = $this->customerMapBuilder->build($customerIds);

        // Account & Order Updates, not Promotional - this is about the customer's own
        // already-placed order, same bucket as credit-limit alerts/reorder reminders, not a
        // marketing/recovery send driven by business goals.
        $consentByCustomer = $this->consentManager->hasTopicConsentForCustomers(
            $customerIds,
            ConsentChannel::Email,
            ConsentTopic::AccountUpdates
        );

        $sent = 0;
        foreach ($orders as $order) {
            $customerId = $order['customer_id'];
            if (!isset($customerMap[$customerId]) || !($consentByCustomer[$customerId] ?? true)) {
                continue;
            }

            $reminderLogRow = [
                'order_id' => $order['order_id'],
                'customer_id' => $customerId,
                'sent_at' => date('Y-m-d H:i:s'),
            ];

            // Claim BEFORE sending, not after - same reasoning as every other reminder cron here:
            // a crash between a successful send and the log write must never cause a resend.
            if (!$this->reminderLogStore->claim(
                self::REMINDER_LOG_TABLE,
                ['order_id = ?' => $order['order_id']],
                $reminderLogRow
            )) {
                continue;
            }

            try {
                $customer = $customerMap[$customerId];
                $this->emailSender->send(
                    self::XML_PATH_EMAIL_TEMPLATE,
                    $this->buildTemplateVars($order, $customer->getFirstname()),
                    $customer->getEmail(),
                    $customer->getFirstname(),
                    $this->emailSender->getStore($this->customerStoreResolver->resolveStoreId($customer))
                );
                $sent++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::REMINDER_LOG_TABLE, $reminderLogRow);
                $this->cronRunLogger->logFailure(
                    sprintf('send stalled payment reminder for order #%d', $order['order_id']),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d stalled payment reminder(s)', $sent));
    }

    /**
     * Registered customers only - a guest checkout has no reliable identity for this module to
     * target, same convention Cron\CalculateReorderCycle already uses. Already-reminded orders
     * are excluded by the LEFT JOIN below as a cheap pre-filter; ReminderLogStore::claim() is
     * still the actual, race-safe guard against a double-send.
     *
     * @return array<int, array{order_id: int, customer_id: int, increment_id: string}>
     */
    private function fetchStalledOrders(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $logTable = $this->resourceConnection->getTableName(self::REMINDER_LOG_TABLE);

        $cutoff = date('Y-m-d H:i:s', (int) strtotime('-' . $this->config->getStalledPaymentLeadHours() . ' hours'));

        /** @var array<int, array{order_id: int|string, customer_id: int|string, increment_id: string}> $rows */
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['o' => $orderTable], [
                    'order_id' => 'entity_id',
                    'customer_id' => 'customer_id',
                    'increment_id' => 'increment_id',
                ])
                ->joinLeft(['l' => $logTable], 'l.order_id = o.entity_id', [])
                ->where('o.state = ?', Order::STATE_PENDING_PAYMENT)
                ->where('o.customer_id IS NOT NULL')
                ->where('o.created_at <= ?', $cutoff)
                ->where('l.entity_id IS NULL')
        );

        return array_map(
            static fn (array $row): array => [
                'order_id' => (int) $row['order_id'],
                'customer_id' => (int) $row['customer_id'],
                'increment_id' => (string) $row['increment_id'],
            ],
            $rows
        );
    }

    /**
     * @param array{order_id: int, customer_id: int, increment_id: string} $order
     * @return array<string, mixed>
     */
    private function buildTemplateVars(array $order, string $customerName): array
    {
        return [
            'customer_name' => $customerName,
            'order_increment_id' => $order['increment_id'],
        ];
    }
}
