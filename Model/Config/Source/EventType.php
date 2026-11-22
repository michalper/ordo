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
 *
 * search_no_results (docs/adr/0076) is also selectable here even though it's primarily a live
 * campaign trigger (Api\Data\CampaignTriggerInterface::TRIGGER_SEARCH_NO_RESULTS) - Controller\
 * Track\Event writes the same event_type into ordo_visitor_event (event_key = the search query
 * text) for every tracked event, live triggers included, so it's immediately usable as a
 * segmentation condition too (e.g. "has searched for something with no results in the last 7
 * days"), with zero new condition code.
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
            ['value' => 'search_no_results', 'label' => __('Searched, No Results')],
        ];
    }
}
