<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One cascading cart-subtotal threshold for a free-gift offer. Every tier whose min_subtotal
 * the cart has reached ADDS its gift_slots to the total earned slots — tiers are cumulative,
 * not "highest tier wins".
 */
interface FreeGiftOfferTierInterface
{
    public const ENTITY_ID = 'entity_id';
    public const OFFER_ID = 'offer_id';
    public const MIN_SUBTOTAL = 'min_subtotal';
    public const GIFT_SLOTS = 'gift_slots';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return int
     */
    public function getOfferId(): int;

    /**
     * @param int $offerId
     * @return $this
     */
    public function setOfferId(int $offerId): self;

    /**
     * @return float
     */
    public function getMinSubtotal(): float;

    /**
     * @param float $minSubtotal
     * @return $this
     */
    public function setMinSubtotal(float $minSubtotal): self;

    /**
     * @return int
     */
    public function getGiftSlots(): int;

    /**
     * @param int $giftSlots
     * @return $this
     */
    public function setGiftSlots(int $giftSlots): self;
}
