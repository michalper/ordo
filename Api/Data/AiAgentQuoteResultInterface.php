<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * The one-response-in-one-call DTO AiAgentQuoteManagementInterface::getQuote() returns, assembled
 * the same way Model\Customer360\Customer360SnapshotBuilder assembles several existing
 * calculators into one DTO - here from an ephemeral (never persisted) Magento\Quote\Model\Quote
 * built for exactly this request, not from a real cart.
 */
interface AiAgentQuoteResultInterface
{
    /**
     * @return string
     */
    public function getCurrency(): string;

    /**
     * @param string $currency
     * @return $this
     */
    public function setCurrency(string $currency): self;

    /**
     * @return float
     */
    public function getSubtotal(): float;

    /**
     * @param float $subtotal
     * @return $this
     */
    public function setSubtotal(float $subtotal): self;

    /**
     * @return float
     */
    public function getDiscountAmount(): float;

    /**
     * @param float $discountAmount
     * @return $this
     */
    public function setDiscountAmount(float $discountAmount): self;

    /**
     * @return float
     */
    public function getShippingAmount(): float;

    /**
     * @param float $shippingAmount
     * @return $this
     */
    public function setShippingAmount(float $shippingAmount): self;

    /**
     * @return float
     */
    public function getGrandTotal(): float;

    /**
     * @param float $grandTotal
     * @return $this
     */
    public function setGrandTotal(float $grandTotal): self;

    /**
     * A flat, config-driven estimate (Config::getAiAgentEstimatedDeliveryDays()) - this module
     * has no real per-carrier/per-route logistics data, so this is deliberately a store-wide
     * placeholder, not a per-order calculation.
     *
     * @return int
     */
    public function getEstimatedDeliveryDays(): int;

    /**
     * @param int $estimatedDeliveryDays
     * @return $this
     */
    public function setEstimatedDeliveryDays(int $estimatedDeliveryDays): self;

    /**
     * @return \Ordo\Automation\Api\Data\AiAgentQuoteLineInterface[]
     */
    public function getLines(): array;

    /**
     * @param \Ordo\Automation\Api\Data\AiAgentQuoteLineInterface[] $lines
     * @return $this
     */
    public function setLines(array $lines): self;

    /**
     * SKUs from the request that don't match any product - skipped rather than failing the
     * whole quote, so one bad SKU among many doesn't block pricing the rest.
     *
     * @return string[]
     */
    public function getUnmatchedSkus(): array;

    /**
     * @param string[] $unmatchedSkus
     * @return $this
     */
    public function setUnmatchedSkus(array $unmatchedSkus): self;
}
