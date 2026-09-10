<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Mirrors FrequencyCapGate's "check, and if blocked, don't proceed" shape, called identically
 * from each Send* campaign action - but where FrequencyCapGate's blocked branch permanently
 * suppresses a send, this one DEFERS it: it writes a scheduled-action row (via
 * CampaignDispatcher::deferActionUntil(), reusing the exact same ordo_campaign_scheduled_action
 * mechanism delay_minutes actions already use) pointing back at the same action, timed for the
 * next moment quiet hours end in the customer's own timezone. Cron\RunScheduledCampaignActions
 * then resumes and re-runs the send normally - this gate is checked again at that point too, so
 * a customer whose quiet-hours window somehow still applies (clock skew, config changed
 * mid-flight) is deferred again rather than force-sent.
 */
class QuietHoursGate
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerTimezoneResolver $customerTimezoneResolver,
        private readonly QuietHoursCalculator $quietHoursCalculator,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return bool true if the send may proceed, false if it was deferred (already scheduled to
     *     resume later - the caller just needs to stop, not log/schedule anything itself)
     */
    public function allows(int $customerId, int $campaignId, int $actionId, array $context, ?int $storeId = null): bool
    {
        if (!$this->config->isQuietHoursEnabled($storeId)) {
            return true;
        }

        if ($actionId <= 0) {
            // A synthetic (never-persisted) action - e.g. a split-variant's own action, which has
            // no real ordo_campaign_action row - can't be safely deferred: deferActionUntil()'s
            // resume_action_id is a hard FK to that table. Same known limitation delay_minutes
            // already has inside split variants (see CampaignDispatcher::runSplit()'s docblock);
            // proceed with the send rather than silently dropping it.
            return true;
        }

        $timezone = $this->customerTimezoneResolver->resolve($customerId, $storeId);
        $startHour = $this->config->getQuietHoursStartHour($storeId);
        $endHour = $this->config->getQuietHoursEndHour($storeId);
        $nowUtc = new \DateTimeImmutable('@' . $this->dateTime->gmtTimestamp());

        if (!$this->quietHoursCalculator->isWithinQuietHours($timezone, $startHour, $endHour, $nowUtc)) {
            return true;
        }

        $runAtUtc = $this->quietHoursCalculator->nextQuietHoursEndUtc($timezone, $endHour, $nowUtc);

        $this->logger->info(sprintf(
            'Ordo_Automation: campaign #%d action #%d deferred for customer #%d until %s (quiet hours).',
            $campaignId,
            $actionId,
            $customerId,
            $runAtUtc->format('Y-m-d H:i:s')
        ));

        $this->campaignDispatcher->deferActionUntil($campaignId, $actionId, $runAtUtc->format('Y-m-d H:i:s'), $context);

        return false;
    }
}
