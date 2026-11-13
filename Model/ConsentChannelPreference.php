<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface;

class ConsentChannelPreference extends DataObject implements ConsentChannelPreferenceInterface
{
    public function getConsented(): bool
    {
        return (bool) $this->getData('consented');
    }

    public function setConsented(bool $consented): self
    {
        $this->setData('consented', $consented);
        return $this;
    }

    public function getPromotional(): bool
    {
        return (bool) $this->getData('promotional');
    }

    public function setPromotional(bool $promotional): self
    {
        $this->setData('promotional', $promotional);
        return $this;
    }

    public function getAccountUpdates(): bool
    {
        return (bool) $this->getData('account_updates');
    }

    public function setAccountUpdates(bool $accountUpdates): self
    {
        $this->setData('account_updates', $accountUpdates);
        return $this;
    }
}
