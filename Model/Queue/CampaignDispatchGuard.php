<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

/**
 * Shared (Magento DI's default singleton scope — see di.xml) reentrancy flag between
 * CampaignDispatchConsumer and CampaignDispatchPublisher. A plain instance property instead of
 * a static one deliberately, per Magento's own coding standard: a static method/property can't
 * be intercepted by a plugin, which a shared-singleton service can.
 */
class CampaignDispatchGuard
{
    private bool $isConsuming = false;

    public function isConsuming(): bool
    {
        return $this->isConsuming;
    }

    public function setConsuming(bool $isConsuming): void
    {
        $this->isConsuming = $isConsuming;
    }
}
