<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Gdpr;

/**
 * Single source of truth for "every table this module holds that is keyed by customer_id" —
 * previously duplicated independently in CustomerDataEraser::TABLES and
 * CustomerDataExporter::export()'s inline fetch() calls, the exact "quietly goes stale" pattern
 * that already bit SetConsent's channel list once (see that class's own fix history in
 * docs/CHANGELOG.md). A table added to one list but not the other would silently mean either an
 * erasure that misses data (compliance risk) or an export that's incomplete - both now read from
 * here instead.
 *
 * Went stale TWICE already, in exactly the way this doc warns about. First: a follow-up audit
 * found the list was missing ordo_customer_rfm_score, ordo_trigger_outcome_log,
 * ordo_campaign_outcome_log, ordo_push_subscription, ordo_reorder_cycle, ordo_offer and
 * ordo_credit_limit_alert_log. Second (2026-09-17, found during live end-to-end verification of
 * unrelated features): eight more tables had accumulated a customer_id column since without ever
 * being added here - ordo_browse_abandoned_reminder_log, ordo_campaign_attribution,
 * ordo_campaign_scheduled_action, ordo_conversation_message, ordo_customer_clv_score,
 * ordo_price_watch_subscription, ordo_push_send_retry, and ordo_visitor_event. That last one is
 * the most important miss: this class used to document ordo_visitor_event as deliberately
 * excluded because it was "keyed by visitor_id, an anonymous browser-cookie identifier this
 * module never links back to a customer_id" - true when that reasoning was written, but
 * Observer\StitchVisitorIdentity has since added a nullable customer_id column to that exact
 * table (set once identity stitching links a visitor to a logged-in customer), silently making
 * that reasoning wrong: a customer's browsing history, once linked to their account, was neither
 * erased nor exported by a GDPR request. There is no automated check that this list stays
 * complete - re-verify against db_schema.xml for every table with a customer_id column whenever
 * a new one is added, don't trust this list is still exhaustive just because it was once.
 */
class CustomerDataTableProvider
{
    /**
     * Table name => export payload key. Order is preserved in both the eraser's per-table result
     * map and the exporter's payload, matching what each already produced before this extraction.
     *
     * @var array<string, string>
     */
    private const array TABLES_TO_EXPORT_KEYS = [
        'ordo_customer_consent' => 'consent',
        'ordo_customer_consent_log' => 'consent_history',
        'ordo_customer_tag' => 'tags',
        'ordo_customer_score' => 'score',
        'ordo_customer_demographic_score' => 'demographic_score',
        'ordo_notification' => 'notifications',
        'ordo_survey_prompt' => 'survey_responses',
        'ordo_pending_popup' => 'pending_popups',
        'ordo_message_log' => 'message_log',
        'ordo_customer_rfm_score' => 'rfm_score',
        'ordo_trigger_outcome_log' => 'trigger_outcome_log',
        'ordo_campaign_outcome_log' => 'campaign_outcome_log',
        'ordo_push_subscription' => 'push_subscriptions',
        'ordo_reorder_cycle' => 'reorder_cycles',
        'ordo_offer' => 'offers',
        'ordo_credit_limit_alert_log' => 'credit_limit_alert_log',
        'ordo_browse_abandoned_reminder_log' => 'browse_abandoned_reminder_log',
        'ordo_campaign_attribution' => 'campaign_attribution',
        'ordo_campaign_scheduled_action' => 'campaign_scheduled_action',
        'ordo_conversation_message' => 'conversation_messages',
        'ordo_customer_clv_score' => 'clv_score',
        'ordo_price_watch_subscription' => 'price_watch_subscriptions',
        'ordo_push_send_retry' => 'push_send_retry',
        'ordo_visitor_event' => 'visitor_events',
    ];

    /**
     * @return string[]
     */
    public function getTables(): array
    {
        return array_keys(self::TABLES_TO_EXPORT_KEYS);
    }

    /**
     * @return array<string, string> table name => export payload key
     */
    public function getExportKeysByTable(): array
    {
        return self::TABLES_TO_EXPORT_KEYS;
    }
}
