<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\SendSms;
use Ordo\Automation\Model\Campaign\Action\SendWhatsApp;
use Ordo\Automation\Model\Config\Source\AbandonedCartFallbackChannel;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\MessageLogEvent;

/**
 * ROADMAP.md's "Cross-channel fallback for cart abandonment" — closes the gap: if the fixed
 * abandoned-cart reminder email (Cron\SendAbandonedCartReminders) goes unopened for the
 * configured delay, this falls back to SMS or WhatsApp instead of adding another email step.
 * Off by default (Helper\Config::isAbandonedCartFallbackEnabled()), same "don't silently add a
 * new message channel" posture as every other opt-in feature in this module.
 *
 * Reuses the real send_sms/send_whatsapp campaign actions directly (not through the campaign
 * engine/ActionPool - there's no campaign dispatch here, just a fixed reminder's own follow-up),
 * so consent, frequency-cap, and quiet-hours gating all apply exactly the same way a real
 * campaign send would. Only ever applies to a registered customer - a guest quote's reminder is
 * never logged with a message_log_id in the first place (see SendAbandonedCartReminders' own
 * sendReminder()), since there's no customer record to resolve a phone number from anyway.
 *
 * "Opened" is read from the real ordo_message_log_event row a genuine SendGrid Event Webhook
 * open event would have written (Controller\Email\StatusCallback) - not a proxy signal like
 * "clicked" or "converted", since the whole point is reacting to an email nobody has even seen
 * yet.
 *
 * @phpstan-type DueRow array{
 *     log_entity_id: int|string,
 *     customer_id: int|string,
 *     customer_email: string,
 *     customer_firstname: string|null,
 *     subtotal: float|string
 * }
 */
class SendAbandonedCartFallbackReminders
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly SendSms $sendSms,
        private readonly SendWhatsApp $sendWhatsApp,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isAbandonedCartFallbackEnabled()) {
            return;
        }

        $delayHours = $this->config->getAbandonedCartFallbackDelayHours();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$delayHours} hours"));
        $channel = $this->config->getAbandonedCartFallbackChannel();

        $rows = $this->fetchDueRows($cutoff);

        $attempted = 0;
        foreach ($rows as $row) {
            // Claim first, same "must never re-attempt on the next tick regardless of outcome"
            // reasoning as the fixed reminder's own claim-before-send - a fallback send action's
            // own consent/frequency-cap checks may legitimately no-op it, and that's still a
            // final answer for this reminder, not something to retry hourly.
            $this->claimFallback((int) $row['log_entity_id']);

            $this->sendFallback($channel, $row);
            $attempted++;
        }

        $this->cronRunLogger->logSummary(sprintf(
            'attempted %d cross-channel abandoned-cart fallback(s) via %s',
            $attempted,
            $channel
        ));
    }

    /**
     * @return array<int, DueRow>
     */
    private function fetchDueRows(string $cutoff): array
    {
        $connection = $this->resourceConnection->getConnection();
        $logTable = $this->resourceConnection->getTableName('ordo_abandoned_cart_reminder_log');
        $quoteTable = $this->resourceConnection->getTableName('quote');
        $eventTable = $this->resourceConnection->getTableName('ordo_message_log_event');

        $openedJoinCondition = (string) $connection->quoteInto(
            'mle.message_log_id = l.message_log_id AND mle.event_type = ?',
            MessageLogEvent::TYPE_OPENED
        );

        $select = $connection->select()
            ->from(
                ['l' => $logTable],
                ['log_entity_id' => 'l.entity_id']
            )
            ->joinInner(
                ['q' => $quoteTable],
                'q.entity_id = l.quote_id',
                ['customer_id', 'customer_email', 'customer_firstname', 'subtotal']
            )
            ->joinLeft(
                ['mle' => $eventTable],
                $openedJoinCondition,
                []
            )
            ->where('l.fallback_sent_at IS NULL')
            ->where('l.message_log_id IS NOT NULL')
            ->where('l.sent_at <= ?', $cutoff)
            ->where('q.customer_id IS NOT NULL')
            // No matching 'opened' event row - the whole point of this join (the condition
            // itself already filters to event_type='opened', see $openedJoinCondition above), so
            // a NULL here means genuinely never opened, not "opened 0 times" via a COUNT/HAVING
            // aggregate this select doesn't otherwise need.
            ->where('mle.entity_id IS NULL');

        /** @var array<int, DueRow> $rows */
        $rows = $connection->fetchAll($select);

        return $rows;
    }

    private function claimFallback(int $logEntityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_abandoned_cart_reminder_log');

        $connection->update(
            $table,
            ['fallback_sent_at' => date('Y-m-d H:i:s')],
            $connection->quoteInto('entity_id = ?', $logEntityId)
        );
    }

    /**
     * @param DueRow $row
     */
    private function sendFallback(string $channel, array $row): void
    {
        $context = ['customer_id' => (int) $row['customer_id']];
        $cartText = sprintf(
            'You left something in your cart! Complete your order of %s.',
            (string) $row['subtotal']
        );

        if ($channel === AbandonedCartFallbackChannel::WHATSAPP) {
            $templateId = $this->config->getAbandonedCartFallbackWhatsAppTemplateId();
            if ($templateId <= 0) {
                return;
            }

            $this->sendWhatsApp->execute($context, ['template_id' => (string) $templateId]);
            return;
        }

        $this->sendSms->execute($context, ['message' => $cartText]);
    }
}
