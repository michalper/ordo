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
 * ordo_visitor_event is deliberately NOT included - it is keyed by visitor_id, an anonymous
 * browser-cookie identifier this module never links back to a customer_id by design (see
 * Observer\StitchVisitorIdentity's own doc on why that stitching only ever flows into tags/score,
 * not a stored customer_id column on the event rows themselves).
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
        'ordo_customer_tag' => 'tags',
        'ordo_customer_score' => 'score',
        'ordo_customer_demographic_score' => 'demographic_score',
        'ordo_notification' => 'notifications',
        'ordo_survey_prompt' => 'survey_responses',
        'ordo_pending_popup' => 'pending_popups',
        'ordo_message_log' => 'message_log',
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
