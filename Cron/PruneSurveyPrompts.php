<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

/**
 * Deletes survey prompts that are no longer relevant: already answered (kept briefly for
 * debugging/observability — nothing ever reads a responded row again except
 * NpsScoreAtLeast::addLatestResponseFilter(), which only wants the single most recent one, not an
 * unbounded history) or expired without ever being polled. Same enforcement role as
 * PrunePendingPopups/PruneNotifications, for the same reason: ordo_survey_prompt is meant to be a
 * short-lived queue, not an ever-growing log.
 */
class PruneSurveyPrompts extends AbstractGracePeriodPruneCron
{
    private const int RESPONDED_GRACE_HOURS = 24;
    private const int DELIVERED_UNANSWERED_GRACE_HOURS = 24;

    protected function tableName(): string
    {
        return 'ordo_survey_prompt';
    }

    public function execute(): void
    {
        $deletedResponded = $this->deleteWherePastGrace('responded_at', self::RESPONDED_GRACE_HOURS);

        $deletedExpired = $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName($this->tableName()),
            [
                'responded_at IS NULL',
                'delivered_at IS NULL',
                'expires_at IS NOT NULL',
                'expires_at < ?' => date('Y-m-d H:i:s'),
            ]
        );

        // Delivered (shown on-screen) but never answered — the visitor isn't coming back to it,
        // same reasoning as PrunePendingPopups.DELIVERED_GRACE_HOURS for a popup nobody clicked.
        $deletedStaleDelivered = $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName($this->tableName()),
            [
                'responded_at IS NULL',
                'delivered_at IS NOT NULL',
                'delivered_at < ?' => date('Y-m-d H:i:s', strtotime('-' . self::DELIVERED_UNANSWERED_GRACE_HOURS . ' hours')),
            ]
        );

        $this->cronRunLogger->logSummary(sprintf(
            'pruned %d responded, %d expired-undelivered and %d stale delivered-unanswered survey prompts',
            $deletedResponded,
            $deletedExpired,
            $deletedStaleDelivered
        ));
    }
}
