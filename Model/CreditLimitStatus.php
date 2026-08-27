<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\CreditLimitStatusInterface;

class CreditLimitStatus extends DataObject implements CreditLimitStatusInterface
{
    public function getCreditLimit(): float
    {
        return (float) $this->getData('credit_limit');
    }

    public function setCreditLimit(float $creditLimit): self
    {
        $this->setData('credit_limit', $creditLimit);
        return $this;
    }

    public function getUsedCredit(): float
    {
        return (float) $this->getData('used_credit');
    }

    public function setUsedCredit(float $usedCredit): self
    {
        $this->setData('used_credit', $usedCredit);
        return $this;
    }

    public function getAvailableCredit(): float
    {
        return (float) $this->getData('available_credit');
    }

    public function setAvailableCredit(float $availableCredit): self
    {
        $this->setData('available_credit', $availableCredit);
        return $this;
    }

    public function getUtilizationPercent(): float
    {
        return (float) $this->getData('utilization_percent');
    }

    public function setUtilizationPercent(float $utilizationPercent): self
    {
        $this->setData('utilization_percent', $utilizationPercent);
        return $this;
    }
}
