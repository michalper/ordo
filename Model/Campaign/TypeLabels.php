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
    private const array CONDITION_LABELS = [
        'tag' => 'Has Tag',
        'order_total_gte' => 'Order Total ≥',
        'visitor_tag' => 'Visitor Has Tag (anonymous)',
        'score_at_least' => 'Score At Least',
        'recency_days_at_most' => 'Recency (days since last order) At Most',
        'order_frequency_at_least' => 'Order Frequency At Least',
        'monetary_total_at_least' => 'Monetary Total At Least',
        'recency_percentile_at_least' => 'Recency Percentile At Least (top N% most recent)',
        'order_frequency_percentile_at_least' => 'Order Frequency Percentile At Least (top N%)',
        'monetary_percentile_at_least' => 'Monetary Percentile At Least (top N% by spend)',
        'in_segment' => 'In Segment',
    ];

    private const array ACTION_LABELS = [
        'add_tag' => 'Add Tag',
        'send_email' => 'Send Email',
        'generate_coupon' => 'Generate Coupon',
        'popup' => 'Show Popup',
        'add_points' => 'Add Points',
        'add_product_recommendations' => 'Add Product Recommendations',
        'add_dynamic_content' => 'Add Dynamic Content',
        'send_sms' => 'Send SMS',
    ];

    public function conditionLabel(string $type): string
    {
        return self::CONDITION_LABELS[$type] ?? $this->humanize($type);
    }

    public function actionLabel(string $type): string
    {
        return self::ACTION_LABELS[$type] ?? $this->humanize($type);
    }

    private function humanize(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }
}
