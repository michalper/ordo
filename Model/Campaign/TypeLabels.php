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
 *
 * Built from literal __('...') calls per entry, not a plain string constant lookup - confirmed
 * directly against a real screenshot: every one of these stayed in English in a Polish admin
 * session before this change, a deeper gap than campaign-flow-editor.js's own hardcoded JS
 * strings (which is what this file's own docblock + the ROADMAP item that prompted fixing those
 * originally assumed these labels already went through). A dynamic __($variable) call resolves
 * correctly at runtime but isn't discoverable by bin/magento i18n:collect-phrases (phpcs's own
 * Magento2.Legacy.Translate sniff flags it) - each literal below keeps that tool useful for
 * future additions. humanize()'s own dynamic fallback (for a type this module doesn't know
 * about) is passed through __() too - harmless, a CSV miss just returns the key text unchanged,
 * same fallback behavior as before.
 */
class TypeLabels
{
    /**
     * @return array<string, string>
     */
    private function conditionLabels(): array
    {
        return [
            'tag' => (string) __('Has Tag'),
            'order_total_gte' => (string) __('Order Total ≥'),
            'visitor_tag' => (string) __('Visitor Has Tag (anonymous)'),
            'score_at_least' => (string) __('Score At Least'),
            'recency_days_at_most' => (string) __('Recency (days since last order) At Most'),
            'order_frequency_at_least' => (string) __('Order Frequency At Least'),
            'monetary_total_at_least' => (string) __('Monetary Total At Least'),
            'recency_percentile_at_least' => (string) __('Recency Percentile At Least (top N% most recent)'),
            'order_frequency_percentile_at_least' => (string) __('Order Frequency Percentile At Least (top N%)'),
            'monetary_percentile_at_least' => (string) __('Monetary Percentile At Least (top N% by spend)'),
            'in_segment' => (string) __('In Segment'),
            'not_in_segment' => (string) __('Not In Segment'),
            'loyalty_tier_at_least' => (string) __('Loyalty Tier At Least'),
            'nps_score_at_least' => (string) __('NPS Score At Least'),
            'clv_at_least' => (string) __('CLV At Least'),
            'purchased_sku' => (string) __('Purchased Product (SKU)'),
            'purchased_category' => (string) __('Purchased From Category'),
            'event_occurred' => (string) __('Event Occurred (cart/wishlist add)'),
            'message_engaged' => (string) __('Opened/Clicked Campaign'),
            'reorder_cycle_at_risk' => (string) __('Reorder Cycle At Risk (drift ratio at least)'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function actionLabels(): array
    {
        return [
            'add_tag' => (string) __('Add Tag'),
            'send_email' => (string) __('Send Email'),
            'generate_coupon' => (string) __('Generate Coupon'),
            'popup' => (string) __('Show Popup'),
            'add_points' => (string) __('Add Points'),
            'add_product_recommendations' => (string) __('Add Product Recommendations'),
            'add_dynamic_content' => (string) __('Add Dynamic Content'),
            'send_sms' => (string) __('Send SMS'),
            'notify' => (string) __('Persistent Notification'),
            'nps_survey' => (string) __('NPS Survey'),
            'send_whatsapp' => (string) __('Send WhatsApp'),
            'send_push' => (string) __('Send Push'),
            'split' => (string) __('A/B Split Test'),
            'generate_ai_content' => (string) __('Generate AI Content (Ollama)'),
            'send_webhook' => (string) __('Send Webhook'),
        ];
    }

    /**
     * Groups for the condition type picker (Model\Config\Source\ConditionType) - reported
     * directly against a real screenshot: one flat ~20-item <select> wasn't great UX for finding
     * the right condition. A type not listed here falls into DEFAULT_CONDITION_CATEGORY, same
     * "never needs to be exhaustive" resilience as humanize() gives an unmapped type's label.
     *
     * Each category literal here must match a literal __() call in conditionCategoryOrder()
     * below exactly - ConditionType::toOptionArray() uses this method's return value as an
     * array KEY to group into, then looks that key up again using conditionCategoryOrder()'s own
     * return values. If the two translated that literal differently (or one translated and the
     * other didn't), the lookup would silently miss and a whole category would vanish from the
     * grouped dropdown.
     *
     * @return array<string, string>
     */
    private function conditionCategories(): array
    {
        $tagsAndBehavior = (string) __('Tags & Behavior');
        $orderAndPurchaseHistory = (string) __('Order & Purchase History');
        $scoreAndEngagement = (string) __('Score & Engagement');
        $segments = (string) __('Segments');

        return [
            'tag' => $tagsAndBehavior,
            'visitor_tag' => $tagsAndBehavior,
            'event_occurred' => $tagsAndBehavior,
            'order_total_gte' => $orderAndPurchaseHistory,
            'recency_days_at_most' => $orderAndPurchaseHistory,
            'order_frequency_at_least' => $orderAndPurchaseHistory,
            'monetary_total_at_least' => $orderAndPurchaseHistory,
            'recency_percentile_at_least' => $orderAndPurchaseHistory,
            'order_frequency_percentile_at_least' => $orderAndPurchaseHistory,
            'monetary_percentile_at_least' => $orderAndPurchaseHistory,
            'purchased_sku' => $orderAndPurchaseHistory,
            'purchased_category' => $orderAndPurchaseHistory,
            'reorder_cycle_at_risk' => $orderAndPurchaseHistory,
            'score_at_least' => $scoreAndEngagement,
            'loyalty_tier_at_least' => $scoreAndEngagement,
            'nps_score_at_least' => $scoreAndEngagement,
            'clv_at_least' => $scoreAndEngagement,
            'message_engaged' => $scoreAndEngagement,
            'in_segment' => $segments,
            'not_in_segment' => $segments,
        ];
    }

    private function defaultConditionCategory(): string
    {
        return (string) __('Other');
    }

    /**
     * Display order for the groups above - conditionCategories() is unordered (a plain lookup
     * map), so this is the one place that actually decides category order in the rendered
     * dropdown. The default category last, so an unmapped custom condition type doesn't jump
     * ahead of the categories this module actually curated.
     *
     * @return string[]
     */
    public function conditionCategoryOrder(): array
    {
        return [
            (string) __('Tags & Behavior'),
            (string) __('Order & Purchase History'),
            (string) __('Score & Engagement'),
            (string) __('Segments'),
            $this->defaultConditionCategory(),
        ];
    }

    public function conditionLabel(string $type): string
    {
        return $this->conditionLabels()[$type] ?? $this->humanizedPhrase($type);
    }

    public function conditionCategory(string $type): string
    {
        return $this->conditionCategories()[$type] ?? $this->defaultConditionCategory();
    }

    public function actionLabel(string $type): string
    {
        return $this->actionLabels()[$type] ?? $this->humanizedPhrase($type);
    }

    private function humanizedPhrase(string $type): string
    {
        return (string) __($this->humanize($type));
    }

    private function humanize(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }
}
