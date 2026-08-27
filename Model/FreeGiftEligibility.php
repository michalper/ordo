<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\FreeGiftEligibilityInterface;

class FreeGiftEligibility extends DataObject implements FreeGiftEligibilityInterface
{
    public function getEarnedSlots(): int
    {
        return (int) $this->getData('earned_slots');
    }

    public function setEarnedSlots(int $earnedSlots): self
    {
        $this->setData('earned_slots', $earnedSlots);
        return $this;
    }

    public function getUsedSlots(): int
    {
        return (int) $this->getData('used_slots');
    }

    public function setUsedSlots(int $usedSlots): self
    {
        $this->setData('used_slots', $usedSlots);
        return $this;
    }

    public function getRemainingSlots(): int
    {
        return (int) $this->getData('remaining_slots');
    }

    public function setRemainingSlots(int $remainingSlots): self
    {
        $this->setData('remaining_slots', $remainingSlots);
        return $this;
    }

    public function getEligibleSkus(): array
    {
        return (array) $this->getData('eligible_skus');
    }

    public function setEligibleSkus(array $eligibleSkus): self
    {
        $this->setData('eligible_skus', $eligibleSkus);
        return $this;
    }
}
