<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\AiAgentQuoteItemInterface;

class AiAgentQuoteItem extends DataObject implements AiAgentQuoteItemInterface
{
    public function getSku(): string
    {
        return (string) $this->getData('sku');
    }

    public function setSku(string $sku): self
    {
        $this->setData('sku', $sku);
        return $this;
    }

    public function getQty(): float
    {
        return (float) $this->getData('qty');
    }

    public function setQty(float $qty): self
    {
        $this->setData('qty', $qty);
        return $this;
    }
}
