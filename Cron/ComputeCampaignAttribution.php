<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Campaign\AttributionCalculator;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Refreshes ordo_campaign_attribution — the multi-touch revenue attribution split table (see
 * Model\Campaign\AttributionCalculator's own docblock for the attribution model and why it's a
 * cron pass rather than an inline sales_order_place_after observer). A cron fits this aggregate
 * recomputation the same way Cron\RecomputeRfmScores fits RFM: attribution for a given order
 * depends on that order's customer's whole recent click-through history, which is simplest to
 * (re)compute as a batch pass rather than trying to keep incrementally in sync with every
 * ordo_message_log_event webhook callback that could still arrive after the order was placed.
 *
 * No enable flag, same reasoning as RecomputeRfmScores: this is pure reporting data maintenance
 * with no customer-visible side effect and nothing an admin would want to opt out of — the
 * Campaign grid's attributed-revenue column simply reads zero/blank until this has run.
 */
class ComputeCampaignAttribution
{
    public function __construct(
        private readonly AttributionCalculator $attributionCalculator,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $orderCount = $this->attributionCalculator->computeForRecentOrders();

        $this->cronRunLogger->logSummary(
            sprintf('recomputed campaign attribution for %d order(s)', $orderCount)
        );
    }
}
