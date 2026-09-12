<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Cron\Model\ScheduleFactory as CronScheduleFactory;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledTriggerState;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Finds every scheduled_at/recurring_schedule campaign trigger that is due right now and fires
 * it via CampaignDispatcher::dispatchScheduledTrigger() - Cron\DispatchScheduledCampaignTriggers's
 * whole job is calling this on a schedule (every 5 minutes, see etc/crontab.xml), so this class
 * carries all the actual due-ness logic.
 *
 * scheduled_at fires at most once ever, exactly when its own datetime has passed.
 * recurring_schedule fires every time the current time matches its own cron expression, the
 * same way Magento's own cron scheduler matches a job's <schedule> (via
 * Magento\Cron\Model\Schedule::matchCronExpression() - reused here rather than adding a new
 * cron-expression-parsing dependency). Precision for both is bounded by how often THIS scanner
 * itself runs: a scheduled_at datetime or a recurring_schedule minute field that doesn't land on
 * a 5-minute boundary won't be caught until the next tick that does reach or pass it.
 *
 * Fire-tracking lives in a separate table (ScheduledTriggerState, keyed by
 * (campaign_id, trigger_event) + a hash of the trigger's own params) rather than on
 * ordo_campaign_trigger itself - see that table's own db_schema.xml comment for why:
 * CampaignSaveProcessor deletes and recreates every trigger row on every campaign save, which
 * would otherwise silently reset fire-tracking on an unrelated re-save (e.g. renaming the
 * campaign). Keying additionally on a hash of the params means an actual change to the
 * scheduled date/cron expression is correctly treated as a fresh, not-yet-fired config, since
 * its hash changes too - not something already fired under the old value.
 */
class ScheduledTriggerScanner
{
    private const array SCHEDULED_TRIGGER_EVENTS = [
        CampaignTriggerInterface::TRIGGER_SCHEDULED_AT,
        CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE,
    ];

    /**
     * Lazily created once per scan() call and reused across every recurring_schedule trigger
     * matchesCronExpression() checks - Schedule::matchCronExpression() is a pure function of its
     * own arguments (no state carried between calls), so there's no need for cronScheduleFactory
     * to mint a fresh instance per trigger.
     */
    private ?\Magento\Cron\Model\Schedule $cronSchedule = null;

    public function __construct(
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly ScheduledTriggerState $scheduledTriggerState,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly CronScheduleFactory $cronScheduleFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return int Number of triggers fired.
     */
    public function scan(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fired = 0;

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventsFilter(self::SCHEDULED_TRIGGER_EVENTS);
        $triggers->addEnabledCampaignFilter();

        // One batched SELECT for every campaign this scan will look at, instead of a separate
        // getState() query per trigger inside the loop below - this cron ticks every 5 minutes
        // (etc/crontab.xml), so with N scheduled/recurring triggers configured that used to mean
        // N round trips every tick.
        $campaignIds = [];
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            $campaignIds[] = $trigger->getCampaignId();
        }
        $states = $this->scheduledTriggerState->getStatesForCampaigns($campaignIds);

        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            try {
                if ($this->isDue($trigger, $now, $states)) {
                    $this->fire($trigger, $now);
                    $fired++;
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: failed checking scheduled trigger #%d (campaign #%d): %s',
                    (int) $trigger->getEntityId(),
                    $trigger->getCampaignId(),
                    $e->getMessage()
                ));
            }
        }

        return $fired;
    }

    /**
     * @param array<string, array{config_hash: string, last_fired_at: string|null}> $states
     *  preloaded via ScheduledTriggerState::getStatesForCampaigns(), keyed
     *  "{campaign_id}:{trigger_event}"
     */
    private function isDue(CampaignTrigger $trigger, \DateTimeImmutable $now, array $states): bool
    {
        $configHash = hash('sha256', $trigger->getParamsJson());
        $state = $states[$trigger->getCampaignId() . ':' . $trigger->getTriggerEvent()] ?? null;
        $alreadyFiredUnderThisConfig = $state !== null
            && $state['config_hash'] === $configHash
            && $state['last_fired_at'] !== null;

        if ($trigger->getTriggerEvent() === CampaignTriggerInterface::TRIGGER_SCHEDULED_AT) {
            if ($alreadyFiredUnderThisConfig) {
                return false;
            }

            $scheduledAt = $this->parseDateTime((string) ($trigger->getParams()['scheduled_at'] ?? ''));
            return $scheduledAt instanceof \DateTimeImmutable && $scheduledAt <= $now;
        }

        // recurring_schedule: due every time it matches AND hasn't already fired for this exact
        // minute (under this exact config) - guards against firing twice if the scan cron runs
        // more than once within the same minute.
        if ($alreadyFiredUnderThisConfig) {
            $lastFiredMinute = $this->parseDateTime((string) $state['last_fired_at'])?->format('Y-m-d H:i');
            if ($lastFiredMinute === $now->format('Y-m-d H:i')) {
                return false;
            }
        }

        $cronExpression = (string) ($trigger->getParams()['cron_expression'] ?? '');
        return $cronExpression !== '' && $this->matchesCronExpression($cronExpression, $now);
    }

    private function fire(CampaignTrigger $trigger, \DateTimeImmutable $now): void
    {
        $campaignId = $trigger->getCampaignId();
        $triggerEvent = $trigger->getTriggerEvent();
        $nowFormatted = $now->format('Y-m-d H:i:s');

        $this->campaignDispatcher->dispatchScheduledTrigger($campaignId, [
            'trigger_event' => $triggerEvent,
            'now' => $nowFormatted,
        ]);

        $this->scheduledTriggerState->markFired(
            $campaignId,
            $triggerEvent,
            hash('sha256', $trigger->getParamsJson()),
            $nowFormatted
        );
    }

    private function matchesCronExpression(string $cronExpression, \DateTimeImmutable $now): bool
    {
        $parts = $this->splitCronExpression($cronExpression);

        if ($parts === null) {
            return false;
        }

        $schedule = $this->cronSchedule ??= $this->cronScheduleFactory->create();

        return $schedule->matchCronExpression($parts[0], (int) $now->format('i'))
            && $schedule->matchCronExpression($parts[1], (int) $now->format('H'))
            && $this->matchesCronExpressionDate($cronExpression, $now);
    }

    /**
     * Date-only half of matchesCronExpression() above (day/month/weekday, ignoring the
     * minute/hour fields) — reused by Block\Adminhtml\Campaign\ScheduleCalendar\
     * CampaignScheduleCalendarViewModel to plot which days in a visible month a
     * recurring_schedule trigger falls on, without re-implementing cron-expression matching.
     * Deliberately public: the calendar cares about which DAYS a schedule lands on, not whether
     * this exact minute is due.
     */
    public function matchesCronExpressionDate(string $cronExpression, \DateTimeImmutable $date): bool
    {
        $parts = $this->splitCronExpression($cronExpression);

        if ($parts === null) {
            return false;
        }

        $schedule = $this->cronSchedule ??= $this->cronScheduleFactory->create();

        return $schedule->matchCronExpression($parts[2], (int) $date->format('d'))
            && $schedule->matchCronExpression($parts[3], (int) $date->format('m'))
            // 'w' (0=Sunday..6=Saturday), matching Magento\Cron\Model\Schedule::trySchedule()'s
            // own weekday format for the exact same matchCronExpression() calls - not 'N'
            // (1=Monday..7=Sunday), which would silently misread every weekday field.
            && $schedule->matchCronExpression($parts[4], (int) $date->format('w'));
    }

    /**
     * @return string[]|null Exactly 5 whitespace-separated cron fields, or null if
     *  $cronExpression isn't shaped like one.
     */
    private function splitCronExpression(string $cronExpression): ?array
    {
        $parts = preg_split('/\s+/', trim($cronExpression));

        return is_array($parts) && count($parts) === 5 ? $parts : null;
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $parsed !== false ? $parsed : null;
    }
}
