<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\AiAgentQuoteResultInterface;

class AiAgentQuoteResult extends DataObject implements AiAgentQuoteResultInterface
{
    public function getCurrency(): string
    {
        return (string) $this->getData('currency');
    }

    public function setCurrency(string $currency): self
    {
        $this->setData('currency', $currency);
        return $this;
    }

    public function getSubtotal(): float
    {
        return (float) $this->getData('subtotal');
    }

    public function setSubtotal(float $subtotal): self
    {
        $this->setData('subtotal', $subtotal);
        return $this;
    }

    public function getDiscountAmount(): float
    {
        return (float) $this->getData('discount_amount');
    }

    public function setDiscountAmount(float $discountAmount): self
    {
        $this->setData('discount_amount', $discountAmount);
        return $this;
    }

    public function getShippingAmount(): float
    {
        return (float) $this->getData('shipping_amount');
    }

    public function setShippingAmount(float $shippingAmount): self
    {
        $this->setData('shipping_amount', $shippingAmount);
        return $this;
    }

    public function getGrandTotal(): float
    {
        return (float) $this->getData('grand_total');
    }

    public function setGrandTotal(float $grandTotal): self
    {
        $this->setData('grand_total', $grandTotal);
        return $this;
    }

    public function getEstimatedDeliveryDays(): int
    {
        return (int) $this->getData('estimated_delivery_days');
    }

    public function setEstimatedDeliveryDays(int $estimatedDeliveryDays): self
    {
        $this->setData('estimated_delivery_days', $estimatedDeliveryDays);
        return $this;
    }

    public function getLines(): array
    {
        return (array) $this->getData('lines');
    }

    public function setLines(array $lines): self
    {
        $this->setData('lines', $lines);
        return $this;
    }

    public function getUnmatchedSkus(): array
    {
        return (array) $this->getData('unmatched_skus');
    }

    public function setUnmatchedSkus(array $unmatchedSkus): self
    {
        $this->setData('unmatched_skus', $unmatchedSkus);
        return $this;
    }
}
