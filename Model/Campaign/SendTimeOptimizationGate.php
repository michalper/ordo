<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\CampaignDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Same "check, and if it defers, the caller just stops" shape as QuietHoursGate - and, like it,
 * reuses CampaignDispatcher::deferActionUntil() (the same ordo_campaign_scheduled_action
 * mechanism) rather than any new scheduling machinery. Where QuietHoursGate defers to AVOID a bad
 * time, this defers TOWARD a good one: SendTimeOptimizer's histogram of a customer's own past
 * email opens/clicks.
 *
 * Opt-in per action, not a global toggle: the calling action (today, only SendEmail) reads its own
 * "use_optimal_send_time" flag out of its `params` JSON blob - the exact same place send_email
 * already stores its "template"/"message" settings (ordo_campaign_action.params) - and passes it
 * in as $enabled. This is deliberate: a brand-new opt-in column on ordo_campaign_action would need
 * a schema/setup_version bump and a setup:upgrade for every existing install just to add one
 * boolean that only send_email (so far) cares about, whereas every send action already has a free,
 * schema-less place to carry action-specific settings. Existing campaigns' saved params never
 * mention this key, so `$params['use_optimal_send_time'] ?? false` defaults every campaign
 * created before this feature existed to "off" - no behavior change without an explicit opt-in.
 *
 * Ordering with QuietHoursGate: this gate is meant to be checked BEFORE QuietHoursGate in the send
 * action (see SendEmail::execute()) so an optimal-hour defer takes priority over an immediate
 * quiet-hours defer when both would otherwise fire on the same attempt. This does not need to
 * avoid deferring INTO quiet hours itself - Cron\RunScheduledCampaignActions::resumeScheduledAction
 * re-runs the exact same action row (see CampaignDispatcher::resumeScheduledAction()'s docblock),
 * which means SendEmail::execute() - and therefore QuietHoursGate::allows() - is evaluated again
 * on that resumed attempt, exactly the same as any other attempt. If the computed optimal hour
 * happens to land inside quiet hours, QuietHoursGate simply defers a second time (to quiet hours'
 * end), the same self-correcting behavior its own docblock already documents for clock skew/config
 * changes. No coordination code between the two gates is needed for this to be correct.
 */
class SendTimeOptimizationGate
{
    /**
     * Below this many hours apart, the "optimal" hour and the currently-intended send hour are
     * considered close enough that deferring isn't worth it - it would just delay the send by a
     * few minutes to an hour for a negligible predicted improvement, at the cost of one extra
     * scheduled-action row and cron pass. Any bigger gap is treated as meaningful.
     */
    private const int MIN_MEANINGFUL_HOUR_DIFFERENCE = 1;

    public function __construct(
        private readonly SendTimeOptimizer $sendTimeOptimizer,
        private readonly CustomerTimezoneResolver $customerTimezoneResolver,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return bool true if the send may proceed now, false if it was deferred (already scheduled
     *     to resume later - the caller just needs to stop, not log/schedule anything itself)
     */
    public function allows(
        int $customerId,
        int $campaignId,
        int $actionId,
        array $context,
        bool $enabled,
        ?int $storeId = null
    ): bool {
        if (!$enabled) {
            return true;
        }

        if ($actionId <= 0) {
            // Same synthetic-action limitation as QuietHoursGate::allows() - deferActionUntil()'s
            // resume_action_id is a hard FK to a real ordo_campaign_action row, which a
            // split-variant's synthetic action never is. Proceed rather than silently drop it.
            return true;
        }

        $bestHour = $this->sendTimeOptimizer->getBestHour($customerId, $storeId);
        if ($bestHour === null) {
            // Not enough history to be confident - the safe default is to never delay a send for
            // lack of data.
            return true;
        }

        $timezone = $this->customerTimezoneResolver->resolve($customerId, $storeId);
        $nowUtc = new \DateTimeImmutable('@' . $this->dateTime->gmtTimestamp());
        $nowLocal = $nowUtc->setTimezone($timezone);
        $currentHour = (int) $nowLocal->format('G');

        if ($this->hourDifference($currentHour, $bestHour) < self::MIN_MEANINGFUL_HOUR_DIFFERENCE) {
            return true;
        }

        $runAtLocal = $nowLocal->setTime($bestHour, 0, 0);
        if ($runAtLocal <= $nowLocal) {
            $runAtLocal = $runAtLocal->modify('+1 day');
        }
        $runAtUtc = $runAtLocal->setTimezone(new \DateTimeZone('UTC'));

        $this->logger->info(sprintf(
            'Ordo_Automation: campaign #%d action #%d deferred for customer #%d until %s'
                . ' (predicted optimal send hour %d, local).',
            $campaignId,
            $actionId,
            $customerId,
            $runAtUtc->format('Y-m-d H:i:s'),
            $bestHour
        ));

        $this->campaignDispatcher->deferActionUntil($campaignId, $actionId, $runAtUtc->format('Y-m-d H:i:s'), $context);

        return false;
    }

    /**
     * Shortest distance between two hours on a 24-hour clock (e.g. 23 and 1 are 2 apart, not 22).
     */
    private function hourDifference(int $hourA, int $hourB): int
    {
        $diff = abs($hourA - $hourB);

        return min($diff, 24 - $diff);
    }
}
