<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The "Event" dropdown for the `event_occurred` segment condition
 * (ordo_segment_form.xml/Model\Campaign\Condition\EventOccurred) — the only two producers of a
 * customer-attributed ordo_visitor_event row today are Observer\TrackCartAdd/TrackWishlistAdd
 * (see each class's own docblock). The condition/resolver themselves don't actually restrict
 * event_type to this list — a future event producer just needs this dropdown extended to be
 * selectable from the admin form, same as any other Source class here.
 */
class EventType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'cart_add', 'label' => __('Added to Cart')],
            ['value' => 'wishlist_add', 'label' => __('Added to Wishlist')],
        ];
    }
}
