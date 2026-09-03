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

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getOfferId(): int;

    public function setOfferId(int $offerId): self;

    public function getMinSubtotal(): float;

    public function setMinSubtotal(float $minSubtotal): self;

    public function getGiftSlots(): int;

    public function setGiftSlots(int $giftSlots): self;
}
