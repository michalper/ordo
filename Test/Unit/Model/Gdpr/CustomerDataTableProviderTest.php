<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Gdpr;

use Ordo\Automation\Model\Gdpr\CustomerDataTableProvider;
use PHPUnit\Framework\TestCase;

class CustomerDataTableProviderTest extends TestCase
{
    private CustomerDataTableProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new CustomerDataTableProvider();
    }

    public function testGetTablesReturnsAllThirtyFiveCustomerKeyedTables(): void
    {
        $tables = $this->provider->getTables();

        self::assertCount(35, $tables);
        self::assertContains('ordo_customer_consent', $tables);
        self::assertContains('ordo_customer_consent_topic', $tables);
        self::assertContains('ordo_customer_consent_log', $tables);
        self::assertContains('ordo_customer_tag', $tables);
        self::assertContains('ordo_customer_score', $tables);
        self::assertContains('ordo_customer_demographic_score', $tables);
        self::assertContains('ordo_notification', $tables);
        self::assertContains('ordo_survey_prompt', $tables);
        self::assertContains('ordo_pending_popup', $tables);
        self::assertContains('ordo_message_log', $tables);
        self::assertContains('ordo_customer_rfm_score', $tables);
        self::assertContains('ordo_trigger_outcome_log', $tables);
        self::assertContains('ordo_campaign_outcome_log', $tables);
        self::assertContains('ordo_push_subscription', $tables);
        self::assertContains('ordo_reorder_cycle', $tables);
        self::assertContains('ordo_offer', $tables);
        self::assertContains('ordo_credit_limit_alert_log', $tables);
        // Found 2026-09-17: these 8 had accumulated a real customer_id column in db_schema.xml
        // without ever being added here - see this class's own docblock for the full story,
        // especially ordo_visitor_event (used to be genuinely visitor_id-only, no longer is).
        self::assertContains('ordo_browse_abandoned_reminder_log', $tables);
        self::assertContains('ordo_campaign_attribution', $tables);
        self::assertContains('ordo_campaign_scheduled_action', $tables);
        self::assertContains('ordo_conversation_message', $tables);
        self::assertContains('ordo_customer_clv_score', $tables);
        self::assertContains('ordo_price_watch_subscription', $tables);
        self::assertContains('ordo_push_send_retry', $tables);
        self::assertContains('ordo_visitor_event', $tables);
        // Found 2026-11-07 (security review): both have a non-nullable customer_id in
        // db_schema.xml and had never been added here either.
        self::assertContains('ordo_review_request_log', $tables);
        self::assertContains('ordo_referral_code', $tables);
        // Found 2026-11-07 (full-module review): added by the customer_inactive campaign
        // trigger feature, had a non-nullable customer_id and was never added here either.
        self::assertContains('ordo_customer_inactive_trigger_claim', $tables);
        self::assertContains('ordo_customer_anniversary_trigger_claim', $tables);
        self::assertContains('ordo_segment_blast_trigger_claim', $tables);
        // Found 2026-11-23 (full-repo audit): all three have a non-nullable customer_id in
        // db_schema.xml and had never been added here either.
        self::assertContains('ordo_wishlist_abandoned_reminder_log', $tables);
        self::assertContains('ordo_stalled_payment_reminder_log', $tables);
        self::assertContains('ordo_loyalty_points_ledger', $tables);
        // Found 2026-11-24 (security review): has a non-nullable customer_id in db_schema.xml
        // and had never been added here either, despite its sibling claim tables all being here.
        self::assertContains('ordo_segment_bulk_action_claim', $tables);
        // Added alongside the table itself (docs/adr/0090) - caught by this test immediately,
        // before ever shipping, the exact failure mode every earlier dated comment above
        // documents discovering only after the fact.
        self::assertContains('ordo_loyalty_points_expiring_reminder_log', $tables);
    }

    public function testGetExportKeysByTableMapsEveryTableToItsExportKey(): void
    {
        $map = $this->provider->getExportKeysByTable();

        self::assertSame([
            'ordo_customer_consent' => 'consent',
            'ordo_customer_consent_topic' => 'consent_topic',
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
            'ordo_review_request_log' => 'review_request_log',
            'ordo_referral_code' => 'referral_code',
            'ordo_customer_inactive_trigger_claim' => 'customer_inactive_trigger_claim',
            'ordo_customer_anniversary_trigger_claim' => 'customer_anniversary_trigger_claim',
            'ordo_segment_blast_trigger_claim' => 'segment_blast_trigger_claim',
            'ordo_segment_bulk_action_claim' => 'segment_bulk_action_claim',
            'ordo_wishlist_abandoned_reminder_log' => 'wishlist_abandoned_reminder_log',
            'ordo_stalled_payment_reminder_log' => 'stalled_payment_reminder_log',
            'ordo_loyalty_points_ledger' => 'loyalty_points_ledger',
            'ordo_loyalty_points_expiring_reminder_log' => 'loyalty_points_expiring_reminder_log',
        ], $map);
    }

    public function testGetTablesAndGetExportKeysByTableAgreeOnTheSameTableSet(): void
    {
        self::assertSame($this->provider->getTables(), array_keys($this->provider->getExportKeysByTable()));
    }

    /**
     * The real fix for this class's own documented "went stale six times" problem: parses
     * etc/db_schema.xml directly instead of trusting another hand-maintained list, so a table
     * that gains a customer_id column in the future fails THIS test instead of needing another
     * audit to notice. Only `<column>` elements that are direct children of `<table>` count -
     * `<constraint>`/`<index>` elements also have `<column name="...">` children, but those only
     * reference a column that's already declared elsewhere, they don't declare a new one.
     */
    public function testTablesToExportKeysCoversEveryCustomerIdBearingTable(): void
    {
        $schema = simplexml_load_file(__DIR__ . '/../../../../etc/db_schema.xml');
        self::assertNotFalse($schema, 'Could not parse etc/db_schema.xml');

        $tablesWithCustomerId = [];
        foreach ($schema->table as $table) {
            foreach ($table->column as $column) {
                if ((string) $column['name'] === 'customer_id') {
                    $tablesWithCustomerId[] = (string) $table['name'];
                    break;
                }
            }
        }

        self::assertNotEmpty($tablesWithCustomerId, 'Parsed zero customer_id-bearing tables - check the XPath above against the real db_schema.xml shape.');
        self::assertEqualsCanonicalizing($tablesWithCustomerId, $this->provider->getTables());
    }
}
