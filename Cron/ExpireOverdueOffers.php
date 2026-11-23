<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

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
        private readonly OfferResource $offerResource,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $expired = $this->offerResource->expireOverdue(date('Y-m-d'));

        $this->cronRunLogger->logSummary(sprintf('marked %d offers as expired', $expired));
    }
}
