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

    public function testGetTablesReturnsAllEightCustomerKeyedTables(): void
    {
        $tables = $this->provider->getTables();

        self::assertCount(8, $tables);
        self::assertContains('ordo_customer_consent', $tables);
        self::assertContains('ordo_customer_tag', $tables);
        self::assertContains('ordo_customer_score', $tables);
        self::assertContains('ordo_customer_demographic_score', $tables);
        self::assertContains('ordo_notification', $tables);
        self::assertContains('ordo_survey_prompt', $tables);
        self::assertContains('ordo_pending_popup', $tables);
        self::assertContains('ordo_message_log', $tables);
        self::assertNotContains('ordo_visitor_event', $tables);
    }

    public function testGetExportKeysByTableMapsEveryTableToItsExportKey(): void
    {
        $map = $this->provider->getExportKeysByTable();

        self::assertSame([
            'ordo_customer_consent' => 'consent',
            'ordo_customer_tag' => 'tags',
            'ordo_customer_score' => 'score',
            'ordo_customer_demographic_score' => 'demographic_score',
            'ordo_notification' => 'notifications',
            'ordo_survey_prompt' => 'survey_responses',
            'ordo_pending_popup' => 'pending_popups',
            'ordo_message_log' => 'message_log',
        ], $map);
    }

    public function testGetTablesAndGetExportKeysByTableAgreeOnTheSameTableSet(): void
    {
        self::assertSame($this->provider->getTables(), array_keys($this->provider->getExportKeysByTable()));
    }
}
