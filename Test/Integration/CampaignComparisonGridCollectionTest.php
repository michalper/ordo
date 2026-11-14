<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\CampaignComparison\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for the standalone cross-campaign comparison report grid's own backing
 * collection (Model\ResourceModel\CampaignComparison\Grid\Collection) - found via a full-sweep
 * audit of every admin grid against Test/Mftf/SCENARIOS.md: this controller/collection had ZERO
 * row in that document and ZERO Integration/MFTF coverage, only a Test/Unit on the plain
 * read-only controller action.
 *
 * The collection's own docblock makes several real, DB-backed claims a mocked adapter can't
 * verify: a campaign with zero sends still shows an honest 0/0/$0 row (LEFT JOINs, not INNER),
 * revenue is read from ordo_campaign_attribution (not the non-unique ordo_campaign_outcome_log.
 * order_id), and avg_converted_clv is the average CLV across DISTINCT converted customers - a
 * customer converted twice by the same campaign must count once, not twice. This suite proves all
 * three against a real database, same "no backing table of its own, everything built in
 * _initSelect()" shape Test/Integration/CohortRetentionGridCollectionTest.php already established
 * a precedent for.
 *
 * Campaign rows are real (Model\Campaign/CampaignResource) since this collection's own main_table
 * is ordo_campaign; the outcome/attribution/CLV rows are inserted directly (same reasoning
 * CohortRetentionGridCollectionTest's own attributeOrderToCampaign() already gives: none of these
 * three tables has a foreign key back to a real sales_order row, so a direct insert is a safe,
 * minimal fixture).
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes every
 * campaign/outcome/attribution/CLV row this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/CampaignComparisonGridCollectionTest.php
 */
class CampaignComparisonGridCollectionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $customerIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        if ($this->campaignIds !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_campaign_outcome_log'),
                ['campaign_id IN (?)' => $this->campaignIds]
            );
            $connection->delete(
                $resourceConnection->getTableName('ordo_campaign_attribution'),
                ['campaign_id IN (?)' => $this->campaignIds]
            );
        }

        if ($this->customerIds !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_customer_clv_score'),
                ['customer_id IN (?)' => $this->customerIds]
            );
        }
        $this->customerIds = [];

        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        foreach ($this->campaignIds as $campaignId) {
            /** @var Campaign $campaign */
            $campaign = $campaignFactory->create();
            $campaignResource->load($campaign, $campaignId);
            if ($campaign->getEntityId()) {
                $campaignResource->delete($campaign);
            }
        }
        $this->campaignIds = [];
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createCampaign(): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        /** @var Campaign $campaign */
        $campaign = $campaignFactory->create();
        $campaign->setName('Campaign comparison grid test ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);

        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        return $campaignId;
    }

    private function logOutcome(int $campaignId, int $customerId, bool $converted): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $connection->insert($resourceConnection->getTableName('ordo_campaign_outcome_log'), [
            'campaign_id' => $campaignId,
            'customer_id' => $customerId,
            'sent_at' => date('Y-m-d H:i:s'),
            'acted_at' => $converted ? date('Y-m-d H:i:s') : null,
            'order_id' => $converted ? 999000 + $customerId : null,
        ]);
    }

    private function attributeRevenue(int $campaignId, int $customerId, float $revenue): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $connection->insert($resourceConnection->getTableName('ordo_campaign_attribution'), [
            'order_id' => 998000 + $customerId,
            'campaign_id' => $campaignId,
            'customer_id' => $customerId,
            'attributed_revenue' => $revenue,
            'touch_count' => 1,
        ]);
    }

    private function setClvScore(int $customerId, float $clv): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_customer_clv_score');

        // insertOnDuplicate-free upsert — a customer id reused across test methods must not
        // collide on the table's own PRIMARY KEY (customer_id).
        $connection->delete($table, ['customer_id = ?' => $customerId]);
        $connection->insert($table, ['customer_id' => $customerId, 'clv_score' => $clv]);
        $this->customerIds[] = $customerId;
    }

    private function rowForCampaign(Collection $collection, int $campaignId): ?\Magento\Framework\DataObject
    {
        foreach ($collection->getItems() as $item) {
            if ((int) $item->getData('entity_id') === $campaignId) {
                return $item;
            }
        }

        return null;
    }

    // --- tests -------------------------------------------------------------------------------

    public function testACampaignWithNoSendsAtAllShowsAnHonestZeroRowInsteadOfBeingExcluded(): void
    {
        $campaignId = $this->createCampaign();

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $row = $this->rowForCampaign($collection, $campaignId);

        self::assertNotNull($row, 'A campaign with zero sends must still appear in the report (LEFT JOIN, not INNER).');
        self::assertSame(0, (int) $row->getData('sent'));
        self::assertSame(0, (int) $row->getData('converted'));
        self::assertEqualsWithDelta(0.0, (float) $row->getData('conversion_rate'), 0.001);
        self::assertEqualsWithDelta(0.0, (float) $row->getData('revenue'), 0.001);
        self::assertNull(
            $row->getData('avg_converted_clv'),
            'avg_converted_clv must be null (no data yet), not 0, for a campaign that has converted nobody.'
        );
    }

    public function testSentConvertedAndConversionRateAggregateCorrectlyAcrossRealOutcomeRows(): void
    {
        $campaignId = $this->createCampaign();

        // 3 sends, 1 converted -> conversion_rate = ROUND(1/3 * 100, 1) = 33.3
        $this->logOutcome($campaignId, 555001, converted: true);
        $this->logOutcome($campaignId, 555002, converted: false);
        $this->logOutcome($campaignId, 555003, converted: false);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $row = $this->rowForCampaign($collection, $campaignId);

        self::assertNotNull($row);
        self::assertSame(3, (int) $row->getData('sent'));
        self::assertSame(1, (int) $row->getData('converted'));
        self::assertEqualsWithDelta(33.3, (float) $row->getData('conversion_rate'), 0.01);
    }

    public function testRevenueComesFromAttributionTableNotTheNonUniqueOutcomeLogOrderId(): void
    {
        $campaignId = $this->createCampaign();

        // Two outcome rows share the same order_id on purpose (CampaignOutcomeLogger::markActed()'s
        // own documented fan-out) - if revenue were summed off ordo_campaign_outcome_log instead of
        // ordo_campaign_attribution, it would double count.
        $this->logOutcome($campaignId, 555004, converted: true);
        $this->attributeRevenue($campaignId, 555004, 40.0);
        $this->attributeRevenue($campaignId, 555005, 60.0);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $row = $this->rowForCampaign($collection, $campaignId);

        self::assertNotNull($row);
        self::assertEqualsWithDelta(
            100.0,
            (float) $row->getData('revenue'),
            0.001,
            'revenue must be the real SUM(attributed_revenue) across both attribution rows.'
        );
    }

    public function testAvgConvertedClvCountsEachConvertedCustomerOnceEvenIfConvertedTwice(): void
    {
        $campaignId = $this->createCampaign();

        // Customer 555006 "converted" twice (two real outcome rows, acted_at set on both) -
        // must only contribute its own CLV score ONCE to the average, not twice.
        $this->logOutcome($campaignId, 555006, converted: true);
        $this->logOutcome($campaignId, 555006, converted: true);
        $this->logOutcome($campaignId, 555007, converted: true);
        // A customer who was SENT TO but never converted must not pull the average down.
        $this->logOutcome($campaignId, 555008, converted: false);

        $this->setClvScore(555006, 100.0);
        $this->setClvScore(555007, 50.0);
        $this->setClvScore(555008, 1000.0);

        /** @var Collection $collection */
        $collection = self::$objectManager->create(Collection::class);
        $row = $this->rowForCampaign($collection, $campaignId);

        self::assertNotNull($row);
        self::assertEqualsWithDelta(
            75.0,
            (float) $row->getData('avg_converted_clv'),
            0.001,
            'avg_converted_clv must average (100 + 50) / 2 = 75 - customer 555006 counted once '
            . 'despite converting twice, and the never-converted customer 555008 excluded entirely.'
        );
    }
}
