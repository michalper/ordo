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

    public function testGetTablesReturnsAllSixteenCustomerKeyedTables(): void
    {
        $tables = $this->provider->getTables();

        self::assertCount(16, $tables);
        self::assertContains('ordo_customer_consent', $tables);
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
        self::assertNotContains('ordo_visitor_event', $tables);
    }

    public function testGetExportKeysByTableMapsEveryTableToItsExportKey(): void
    {
        $map = $this->provider->getExportKeysByTable();

        self::assertSame([
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
        ], $map);
    }

    public function testGetTablesAndGetExportKeysByTableAgreeOnTheSameTableSet(): void
    {
        self::assertSame($this->provider->getTables(), array_keys($this->provider->getExportKeysByTable()));
    }
}
