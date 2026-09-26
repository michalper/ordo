<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\AiAgentQuoteLineInterface;

class AiAgentQuoteLine extends DataObject implements AiAgentQuoteLineInterface
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

    public function getUnitPrice(): float
    {
        return (float) $this->getData('unit_price');
    }

    public function setUnitPrice(float $unitPrice): self
    {
        $this->setData('unit_price', $unitPrice);
        return $this;
    }

    public function getRowTotal(): float
    {
        return (float) $this->getData('row_total');
    }

    public function setRowTotal(float $rowTotal): self
    {
        $this->setData('row_total', $rowTotal);
        return $this;
    }
}
