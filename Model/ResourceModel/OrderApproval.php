<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class OrderApproval extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_order_approval', 'entity_id');
    }

    public function loadByToken(\Ordo\Automation\Model\OrderApproval $model, string $token): void
    {
        $connection = $this->getConnection();
        $entityId = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), 'entity_id')
                ->where('token = ?', $token)
        );

        if ($entityId) {
            $this->load($model, (int) $entityId);
        }
    }

    /**
     * Atomically transitions a pending approval to its decided status - a plain load()-then-save()
     * lets two concurrent requests for the same token (a double click, or a forwarded email opened
     * twice) both pass the "still pending" check before either writes, so one order could be both
     * approved and rejected with a silent last-write-wins on status. This does the transition as a
     * single conditional UPDATE ... WHERE status = 'pending', so only whichever request's UPDATE
     * actually matches a row may proceed to touch the order; the loser sees 0 affected rows.
     */
    public function claimPending(\Ordo\Automation\Model\OrderApproval $model, string $status): bool
    {
        $connection = $this->getConnection();
        $decidedAt = date('Y-m-d H:i:s');

        $affectedRows = $connection->update(
            $this->getMainTable(),
            ['status' => $status, 'decided_at' => $decidedAt],
            $connection->quoteInto('entity_id = ?', (int) $model->getEntityId())
            . ' AND status = ' . $connection->quote(\Ordo\Automation\Model\OrderApproval::STATUS_PENDING)
        );

        if ($affectedRows > 0) {
            $model->setData('status', $status);
            $model->setData('decided_at', $decidedAt);
        }

        return $affectedRows > 0;
    }

    /**
     * Atomically claims the next escalation reminder/tier-advance for a stale pending approval -
     * Cron\EscalateStalePendingApprovals::claimRecipientEmail() used to do this as a plain
     * load()-then-save(), which only protects against a crash between send and save within a
     * single cron run (its own docblock's stated goal); it does nothing against two genuinely
     * overlapping runs of the same cron (a manual "Run Now" racing the scheduled instance) both
     * reading the same reminders_sent/escalation_tier, both saving, and both then sending the
     * escalation email. This does the transition as a single conditional
     * UPDATE ... WHERE reminders_sent = ? AND escalation_tier = ?, re-checking the exact values
     * this call read - so only whichever run's UPDATE still matches may proceed to send; the
     * loser sees 0 affected rows and must not send. Same shape as claimPending() above.
     *
     * @param bool $tierAdvanced Whether this claim advances escalation_tier (vs. another
     *   same-tier reminder) - controls which fields get reflected into $model on success, so the
     *   model's own observable state matches exactly what the pre-atomic-claim code did.
     */
    public function claimEscalation(
        \Ordo\Automation\Model\OrderApproval $model,
        int $expectedRemindersSent,
        int $expectedEscalationTier,
        int $newRemindersSent,
        int $newEscalationTier,
        bool $tierAdvanced
    ): bool {
        $connection = $this->getConnection();

        $affectedRows = $connection->update(
            $this->getMainTable(),
            ['reminders_sent' => $newRemindersSent, 'escalation_tier' => $newEscalationTier],
            $connection->quoteInto('entity_id = ?', (int) $model->getEntityId())
            . $connection->quoteInto(' AND reminders_sent = ?', $expectedRemindersSent)
            . $connection->quoteInto(' AND escalation_tier = ?', $expectedEscalationTier)
        );

        if ($affectedRows > 0) {
            // Only reflects what actually changed into the in-memory model - same distinction
            // the pre-atomic-claim code made, since execute()'s own rollback-on-send-failure only
            // restores escalation_tier when $tierAdvanced is true.
            $model->setData('reminders_sent', $newRemindersSent);
            if ($tierAdvanced) {
                $model->setData('escalation_tier', $newEscalationTier);
            }
        }

        return $affectedRows > 0;
    }
}
