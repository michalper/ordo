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
        'not_in_segment' => 'Not In Segment',
        'loyalty_tier_at_least' => 'Loyalty Tier At Least',
        'nps_score_at_least' => 'NPS Score At Least',
        'purchased_sku' => 'Purchased Product (SKU)',
        'purchased_category' => 'Purchased From Category',
        'event_occurred' => 'Event Occurred (cart/wishlist add)',
        'message_engaged' => 'Opened/Clicked Campaign',
        'reorder_cycle_at_risk' => 'Reorder Cycle At Risk (drift ratio at least)',
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
        'notify' => 'Persistent Notification',
        'nps_survey' => 'NPS Survey',
        'send_whatsapp' => 'Send WhatsApp',
        'send_push' => 'Send Push',
        'split' => 'A/B Split Test',
        'generate_ai_content' => 'Generate AI Content (Ollama)',
    ];

    /**
     * Groups for the condition type picker (Model\Config\Source\ConditionType) - reported
     * directly against a real screenshot: one flat ~20-item <select> wasn't great UX for finding
     * the right condition. A type not listed here falls into DEFAULT_CONDITION_CATEGORY, same
     * "never needs to be exhaustive" resilience as humanize() gives an unmapped type's label.
     */
    private const array CONDITION_CATEGORIES = [
        'tag' => 'Tags & Behavior',
        'visitor_tag' => 'Tags & Behavior',
        'event_occurred' => 'Tags & Behavior',
        'order_total_gte' => 'Order & Purchase History',
        'recency_days_at_most' => 'Order & Purchase History',
        'order_frequency_at_least' => 'Order & Purchase History',
        'monetary_total_at_least' => 'Order & Purchase History',
        'recency_percentile_at_least' => 'Order & Purchase History',
        'order_frequency_percentile_at_least' => 'Order & Purchase History',
        'monetary_percentile_at_least' => 'Order & Purchase History',
        'purchased_sku' => 'Order & Purchase History',
        'purchased_category' => 'Order & Purchase History',
        'reorder_cycle_at_risk' => 'Order & Purchase History',
        'score_at_least' => 'Score & Engagement',
        'loyalty_tier_at_least' => 'Score & Engagement',
        'nps_score_at_least' => 'Score & Engagement',
        'clv_at_least' => 'Score & Engagement',
        'message_engaged' => 'Score & Engagement',
        'in_segment' => 'Segments',
        'not_in_segment' => 'Segments',
    ];

    private const string DEFAULT_CONDITION_CATEGORY = 'Other';

    /**
     * Display order for the groups above - CONDITION_CATEGORIES is unordered (a plain lookup
     * map), so this is the one place that actually decides category order in the rendered
     * dropdown. DEFAULT_CONDITION_CATEGORY last, so an unmapped custom condition type doesn't
     * jump ahead of the categories this module actually curated.
     */
    private const array CONDITION_CATEGORY_ORDER = [
        'Tags & Behavior',
        'Order & Purchase History',
        'Score & Engagement',
        'Segments',
        self::DEFAULT_CONDITION_CATEGORY,
    ];

    public function conditionLabel(string $type): string
    {
        return self::CONDITION_LABELS[$type] ?? $this->humanize($type);
    }

    public function conditionCategory(string $type): string
    {
        return self::CONDITION_CATEGORIES[$type] ?? self::DEFAULT_CONDITION_CATEGORY;
    }

    /**
     * @return string[]
     */
    public function conditionCategoryOrder(): array
    {
        return self::CONDITION_CATEGORY_ORDER;
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
