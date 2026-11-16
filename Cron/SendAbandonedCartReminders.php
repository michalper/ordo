<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\Email\MessageIdGenerator;
use Ordo\Automation\Model\Sms\MessageLogWriter;

/**
 * Finds quotes with items that have not been touched for the configured delay,
 * were not converted to an order, and belong to an identifiable customer/email,
 * then sends a one-time (or capped) recovery reminder.
 *
 * Also dispatches a "cart_abandoned" campaign event for quotes tied to a registered
 * customer, alongside the fixed reminder above — a store can layer a coupon or a tag
 * onto cart recovery via a campaign without touching this cron. Guest quotes (no
 * customer_id) only get the fixed email, since campaign conditions/actions here all
 * assume a real customer_id.
 *
 * @phpstan-type AbandonedCartRow array{
 *     entity_id: int|string,
 *     customer_id: int|string|null,
 *     customer_email: string,
 *     customer_firstname: string|null,
 *     subtotal: float|string,
 *     store_id: int|string,
 *     reminders_sent: int|string
 * }
 * @phpstan-type AbandonedCartItem array{name: string, qty: float|string}
 */
class SendAbandonedCartReminders
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_abandoned_cart_reminder';
    private const string LOG_TABLE = 'ordo_abandoned_cart_reminder_log';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly ReminderEmailSender $reminderEmailSender,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly ConsentManager $consentManager,
        private readonly CronRunLogger $cronRunLogger,
        private readonly MessageIdGenerator $messageIdGenerator,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly ReminderLogStore $reminderLogStore
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isAbandonedCartEnabled()) {
            return;
        }

        $delayMinutes = $this->config->getAbandonedCartDelayMinutes();
        $minSubtotal = $this->config->getAbandonedCartMinSubtotal();
        $maxReminders = $this->config->getAbandonedCartMaxReminders();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$delayMinutes} minutes"));

        $connection = $this->resourceConnection->getConnection();
        $quoteTable = $this->resourceConnection->getTableName('quote');
        $logTable = $this->resourceConnection->getTableName(self::LOG_TABLE);

        $select = $connection->select()
            ->from(
                ['q' => $quoteTable],
                ['entity_id', 'customer_id', 'customer_email', 'customer_firstname', 'subtotal', 'store_id']
            )
            ->joinLeft(
                ['l' => $logTable],
                'l.quote_id = q.entity_id',
                ['reminders_sent' => new \Zend_Db_Expr('COUNT(l.entity_id)')]
            )
            ->where('q.is_active = 1')
            ->where('q.items_count > 0')
            ->where('q.updated_at <= ?', $cutoff)
            ->where('q.customer_email IS NOT NULL')
            ->where('q.subtotal >= ?', $minSubtotal)
            ->group('q.entity_id')
            ->having('reminders_sent < ?', $maxReminders);

        /** @var array<int, AbandonedCartRow> $rows */
        $rows = $connection->fetchAll($select);

        // One query for the whole batch instead of one Quote::load() per row inside sendReminder()
        // below - found via a performance audit, same reasoning as every other batch-load in this
        // method. Only the visible line items (name/qty) are actually needed for the email
        // template, so this reads quote_item directly rather than hydrating a full Quote entity
        // (items/addresses/payment/...) per row just to call getAllVisibleItems() on it.
        $itemsByQuoteId = $this->fetchVisibleItemsByQuoteId(array_map(
            static fn (array $row): int => (int) $row['entity_id'],
            $rows
        ));

        // One query for the whole batch instead of one hasConsent() call per row below - found
        // via a performance audit, same reasoning as ConsentManager::hasConsentForCustomers().
        $customerIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['customer_id'],
            array_filter($rows, static fn (array $row): bool => !empty($row['customer_id']))
        )));
        // Promotional - an abandoned-cart recovery send is a marketing/recovery push, see
        // ConsentTopic::Promotional's own docblock.
        $consentByCustomer = $this->consentManager->hasTopicConsentForCustomers(
            $customerIds,
            ConsentChannel::Email,
            ConsentTopic::Promotional
        );

        $sent = 0;
        foreach ($rows as $row) {
            // A registered customer (guest quotes have no customer_id and aren't covered by the
            // consent register at all) who opted out of email must never receive THIS fixed
            // reminder - it's sent directly via ReminderEmailSender, bypassing the campaign engine's
            // own per-action consent gate entirely, so it needs its own check here. The
            // cart_abandoned campaign trigger below is dispatched regardless: its own actions
            // (send_email/send_sms/send_push/send_whatsapp) already check ConsentManager
            // themselves before sending, and a non-channel action (add_tag, generate_coupon, ...)
            // has nothing to do with email consent at all - gating the whole trigger here would
            // silently suppress those too, which used to be this method's actual behavior.
            $hasEmailConsent = empty($row['customer_id'])
                || ($consentByCustomer[(int) $row['customer_id']] ?? true);

            $reminderLogRow = $this->buildReminderLogRow((int) $row['entity_id']);

            try {
                // Claim (log) BEFORE sending, not after - a crash between a successful send and
                // the log write must never cause a duplicate reminder on the next tick. If the
                // send itself then fails, the claim is rolled back so this quote is retried next
                // run. claimUnderLimit() (not a plain insert()) re-checks "reminders_sent <
                // maxReminders" atomically - the batch SELECT's own HAVING clause above is only a
                // fast pre-filter, not the actual guard against two overlapping cron runs both
                // inserting for this quote.
                $claimed = $this->reminderLogStore->claimAndRunUnderLimit(
                    self::LOG_TABLE,
                    ['quote_id = ?' => (int) $row['entity_id']],
                    $maxReminders,
                    $reminderLogRow,
                    function () use ($row, $hasEmailConsent, $reminderLogRow, $itemsByQuoteId): void {
                        if ($hasEmailConsent) {
                            $items = $itemsByQuoteId[(int) $row['entity_id']] ?? [];
                            $messageLogId = $this->sendReminder($row, $items);
                            if ($messageLogId !== null) {
                                // Fills in the message_log_id a successful sendReminder() only
                                // learns after the claim row already exists (see this class's own
                                // docblock on that column), matched by the exact claim values just
                                // inserted.
                                $this->reminderLogStore->updateMatching(
                                    self::LOG_TABLE,
                                    $reminderLogRow,
                                    ['message_log_id' => $messageLogId]
                                );
                            }
                        }
                        $this->dispatchCampaigns($row);
                    }
                );
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('send abandoned cart reminder for quote #%d', (int) $row['entity_id']),
                    $e
                );
                continue;
            }

            if ($claimed) {
                $sent++;
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d abandoned cart reminders', $sent));
    }

    /**
     * @param AbandonedCartRow $row
     * @param AbandonedCartItem[] $items
     * @return int|null the real ordo_message_log row's own entity_id this send was logged as -
     *     only for a registered customer (Cron\SendAbandonedCartFallbackReminders' own cross-
     *     channel fallback needs a real customer_id to send SMS/WhatsApp to regardless, so a
     *     guest quote's send isn't worth logging for that purpose)
     */
    private function sendReminder(array $row, array $items): ?int
    {
        // Same Message-ID wiring Model\Campaign\Action\SendEmail uses - lets Controller\Email\
        // StatusCallback's SendGrid Event Webhook correlate a later "open" event back to this
        // exact send, which Cron\SendAbandonedCartFallbackReminders reads to decide whether to
        // fall back to SMS/WhatsApp.
        $messageId = $this->messageIdGenerator->generate();

        $this->reminderEmailSender->send(
            self::XML_PATH_EMAIL_TEMPLATE,
            [
                'customer_name' => $row['customer_firstname'] ?: 'there',
                'cart_items' => $items,
                'cart_subtotal' => $row['subtotal'],
            ],
            $row['customer_email'],
            $row['customer_firstname'] ?: $row['customer_email'],
            store: $this->reminderEmailSender->getStore((int) $row['store_id']),
            messageId: $messageId
        );

        if (empty($row['customer_id'])) {
            return null;
        }

        return $this->messageLogWriter->recordSent(
            'email',
            (int) $row['customer_id'],
            $row['customer_email'],
            '<' . $messageId . '>'
        );
    }

    /**
     * @param int[] $quoteIds
     * @return array<int, AbandonedCartItem[]>
     */
    private function fetchVisibleItemsByQuoteId(array $quoteIds): array
    {
        if ($quoteIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        /** @var array<int, array{quote_id: int|string, name: string, qty: float|string}> $itemRows */
        $itemRows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('quote_item'), ['quote_id', 'name', 'qty'])
                ->where('quote_id IN (?)', $quoteIds)
                // Top-level items only, same as Quote::getAllVisibleItems() - a child item of a
                // configurable/bundle product is already represented by its parent row.
                ->where('parent_item_id IS NULL')
        );

        $itemsByQuoteId = [];
        foreach ($itemRows as $itemRow) {
            $itemsByQuoteId[(int) $itemRow['quote_id']][] = [
                'name' => $itemRow['name'],
                'qty' => $itemRow['qty'],
            ];
        }

        return $itemsByQuoteId;
    }

    /**
     * @param AbandonedCartRow $row
     */
    private function dispatchCampaigns(array $row): void
    {
        if (empty($row['customer_id'])) {
            return;
        }

        $this->campaignDispatcher->dispatch('cart_abandoned', [
            'customer_id' => (int) $row['customer_id'],
            'cart_subtotal' => (float) $row['subtotal'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReminderLogRow(int $quoteId): array
    {
        return [
            'quote_id' => $quoteId,
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }
}
