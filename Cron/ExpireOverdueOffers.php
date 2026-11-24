<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\Offer as OfferResource;

/**
 * Marks offers past their expiry date as "expired" (they stay "sent" until then, even if the
 * customer never responded). This is what feeds the "expired without a response" figure that
 * a rep can review, instead of offers silently rotting in a "sent" state forever.
 */
class ExpireOverdueOffers
{
    public function __construct(
        private readonly Config $config,
        private readonly OfferResource $offerResource,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        // Config::isOfferReminderEnabled() reads ordo_automation/offer/enabled - despite the
        // name, that's the one toggle for the whole Offer feature (see its own definition),
        // not just the reminder email. Found missing here during a full-repo /code-review -
        // every other Ordo cron is config-gated per feature (CLAUDE.md), this one wasn't, so
        // disabling Offer in admin never stopped it from still mutating offer status.
        if (!$this->config->isOfferReminderEnabled()) {
            return;
        }

        $expired = $this->offerResource->expireOverdue(date('Y-m-d'));

        $this->cronRunLogger->logSummary(sprintf('marked %d offers as expired', $expired));
    }
}
