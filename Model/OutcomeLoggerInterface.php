<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * Shared shape of CampaignOutcomeLogger/TriggerOutcomeLogger's markActed() - the two are already
 * documented as "same shape, same first-plausible-match attribution philosophy" counterparts of
 * each other; this interface only exists so Observer\AbstractRecordOutcomeOnOrderSave can take
 * either one without caring which.
 */
interface OutcomeLoggerInterface
{
    public function markActed(int $customerId, int $orderId): void;
}
