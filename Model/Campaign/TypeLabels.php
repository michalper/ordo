<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

/**
 * Human-readable labels for condition/action type keys — a raw key like "order_total_gte" is
 * meaningful to a developer reading di.xml, not to an admin building a campaign. Trigger labels
 * already have their own canonical source, Model\Config\Source\TriggerEvent — this only covers
 * conditions/actions, which never had one. A type this module doesn't know about (a custom
 * condition/action a store registered itself) still gets something readable via humanize()
 * rather than falling back to the raw key, so the label list never needs to be exhaustive to
 * stay useful.
 */
class TypeLabels
{
    private const CONDITION_LABELS = [
        'tag' => 'Has Tag',
        'order_total_gte' => 'Order Total ≥',
        'visitor_tag' => 'Visitor Has Tag (anonymous)',
    ];

    private const ACTION_LABELS = [
        'add_tag' => 'Add Tag',
        'send_email' => 'Send Email',
        'generate_coupon' => 'Generate Coupon',
        'popup' => 'Show Popup',
    ];

    public function conditionLabel(string $type): string
    {
        return self::CONDITION_LABELS[$type] ?? self::humanize($type);
    }

    public function actionLabel(string $type): string
    {
        return self::ACTION_LABELS[$type] ?? self::humanize($type);
    }

    private static function humanize(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }
}
