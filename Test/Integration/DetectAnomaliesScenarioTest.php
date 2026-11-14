<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\DetectAnomalies;
use Ordo\Automation\Model\Anomaly\AnomalyAlert;
use PHPUnit\Framework\TestCase;

/**
 * Proves Cron\DetectAnomalies' own real detection logic against a real database - the two checks
 * its own docblock/docs/adr/0025 document (campaign_revenue_drop, campaign_conversion_outlier),
 * the minimum-sample-size gate, and the Model\Cron\ReminderLogStore::claim() cooldown/dedup - all
 * through real DI (Helper\Config, Model\CampaignOutcomeLogger, ReminderLogStore) and a real
 * `ordo_campaign_outcome_log`/`ordo_anomaly_alert`/`sales_order` database, not the mocked
 * collaborators Test/Unit/Cron/DetectAnomaliesTest.php already exercises the branching logic
 * with. See Test/Mftf/SCENARIOS.md's new §40/41 row for why this is Integration-only, no MFTF -
 * same reasoning §39 gives for the sibling Referrals grid: `Controller\Adminhtml\AnomalyAlert\
 * Index` is the same already-proven generic `Magento_Ui` read-only listing shape as
 * `Cron\CronRunLog`'s own grid, nothing newly admin-visible to click through.
 *
 * `ordo_campaign_outcome_log.campaign_id`/`customer_id` have no FK (same reasoning as every other
 * historical log table in this module - see etc/db_schema.xml's own comment on that table), so
 * fixture rows use arbitrary int ids with no real Campaign/Customer entity needed at all.
 * `sales_order.customer_id` DOES have a real FK to `customer_entity` though, so fixture orders are
 * created as guest orders (`customer_id` left null) purely to carry a real `base_grand_total` for
 * the revenue join - no real customer needed there either.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes every
 * `ordo_campaign_outcome_log`/`ordo_anomaly_alert` row for this test's own tracked campaign ids
 * and every `sales_order` row it created; tearDownAfterClass() restores the config this test
 * overrides.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/DetectAnomaliesScenarioTest.php
 */
class DetectAnomaliesScenarioTest extends TestCase
{
    private const string CONFIG_PATH_ENABLED = 'ordo_automation/anomaly_alerts/enabled';
    private const string CONFIG_PATH_LOOKBACK_DAYS = 'ordo_automation/anomaly_alerts/lookback_days';
    private const string CONFIG_PATH_MIN_SAMPLE_SIZE = 'ordo_automation/anomaly_alerts/min_sample_size';
    private const string CONFIG_PATH_REVENUE_DROP_THRESHOLD_PERCENT
        = 'ordo_automation/anomaly_alerts/revenue_drop_threshold_percent';
    private const string CONFIG_PATH_CONVERSION_OUTLIER_THRESHOLD_PERCENT
        = 'ordo_automation/anomaly_alerts/conversion_outlier_threshold_percent';
    private const string CONFIG_PATH_COOLDOWN_DAYS = 'ordo_automation/anomaly_alerts/cooldown_days';

    private const int LOOKBACK_DAYS = 7;
    private const int MIN_SAMPLE_SIZE = 3;

    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $orderIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(Registry::class)->register('isSecureArea', true);

        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        $writer = self::$objectManager->get(ConfigWriter::class);
        $writer->save(self::CONFIG_PATH_ENABLED, 1, ScopeInterface::SCOPE_STORES, $storeId);
        $writer->save(self::CONFIG_PATH_LOOKBACK_DAYS, self::LOOKBACK_DAYS, ScopeInterface::SCOPE_STORES, $storeId);
        $writer->save(self::CONFIG_PATH_MIN_SAMPLE_SIZE, self::MIN_SAMPLE_SIZE, ScopeInterface::SCOPE_STORES, $storeId);
        $writer->save(self::CONFIG_PATH_REVENUE_DROP_THRESHOLD_PERCENT, 30, ScopeInterface::SCOPE_STORES, $storeId);
        $writer->save(
            self::CONFIG_PATH_CONVERSION_OUTLIER_THRESHOLD_PERCENT,
            50,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        $writer->save(self::CONFIG_PATH_COOLDOWN_DAYS, 7, ScopeInterface::SCOPE_STORES, $storeId);
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        $writer = self::$objectManager->get(ConfigWriter::class);
        foreach ([
            self::CONFIG_PATH_ENABLED,
            self::CONFIG_PATH_LOOKBACK_DAYS,
            self::CONFIG_PATH_MIN_SAMPLE_SIZE,
            self::CONFIG_PATH_REVENUE_DROP_THRESHOLD_PERCENT,
            self::CONFIG_PATH_CONVERSION_OUTLIER_THRESHOLD_PERCENT,
            self::CONFIG_PATH_COOLDOWN_DAYS,
        ] as $path) {
            $writer->delete($path, ScopeInterface::SCOPE_STORES, $storeId);
        }
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    protected function tearDown(): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $orderTable = $resourceConnection->getTableName('sales_order');
        foreach ($this->orderIds as $orderId) {
            $connection->delete($orderTable, ['entity_id = ?' => $orderId]);
        }
        $this->orderIds = [];

        if ($this->campaignIds !== []) {
            $outcomeTable = $resourceConnection->getTableName('ordo_campaign_outcome_log');
            $alertTable = $resourceConnection->getTableName('ordo_anomaly_alert');
            $connection->delete($outcomeTable, ['campaign_id IN (?)' => $this->campaignIds]);
            $connection->delete($alertTable, ['campaign_id IN (?)' => $this->campaignIds]);
        }
        $this->campaignIds = [];
    }

    public function testFlagsARealRevenueDropAgainstItsOwnTrailingBaseline(): void
    {
        $campaignId = $this->nextCampaignId();

        // Baseline window (8-14 days ago): 3 sends (meets the min sample size), $300 total revenue.
        for ($i = 0; $i < self::MIN_SAMPLE_SIZE; $i++) {
            $orderId = $this->createOrder(100.0);
            $this->insertOutcomeRow($campaignId, daysAgo: 10, orderId: $orderId);
        }

        // Current window (0-7 days ago): 1 send, $30 revenue - a real 90% drop, well above the 30%
        // threshold.
        $orderId = $this->createOrder(30.0);
        $this->insertOutcomeRow($campaignId, daysAgo: 2, orderId: $orderId);

        self::$objectManager->create(DetectAnomalies::class)->execute();

        $alert = $this->findAlert($campaignId, AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP);
        self::assertNotNull($alert, 'A real 90% revenue drop above the configured 30% threshold must be flagged.');
        self::assertEqualsWithDelta(30.0, (float) $alert['current_value'], 0.01);
        self::assertEqualsWithDelta(300.0, (float) $alert['baseline_value'], 0.01);
        self::assertEqualsWithDelta(90.0, (float) $alert['deviation_percent'], 0.01);
        self::assertStringContainsString('revenue dropped', $alert['message']);
    }

    public function testFlagsRealConversionOutliersInBothDirections(): void
    {
        $outperformingId = $this->nextCampaignId();
        $underperformingId = $this->nextCampaignId();

        // Current window, both meet the min sample size: one converts every send (100%), the
        // other converts none (0%) - peer average sits at 50%, so both deviate by a real 100%,
        // well above the 50% threshold, in opposite directions.
        for ($i = 0; $i < self::MIN_SAMPLE_SIZE; $i++) {
            $this->insertOutcomeRow($outperformingId, daysAgo: 2, converted: true);
            $this->insertOutcomeRow($underperformingId, daysAgo: 2, converted: false);
        }

        self::$objectManager->create(DetectAnomalies::class)->execute();

        $outperforming = $this->findAlert($outperformingId, AnomalyAlert::TYPE_CAMPAIGN_CONVERSION_OUTLIER);
        self::assertNotNull($outperforming, 'A real 100%-converting campaign must be flagged as outperforming its peers.');
        self::assertStringContainsString('outperforming', $outperforming['message']);

        $underperforming = $this->findAlert($underperformingId, AnomalyAlert::TYPE_CAMPAIGN_CONVERSION_OUTLIER);
        self::assertNotNull($underperforming, 'A real 0%-converting campaign must be flagged as underperforming its peers.');
        self::assertStringContainsString('underperforming', $underperforming['message']);
    }

    public function testSuppressesNoiseOnALowVolumeCampaignBelowTheMinimumSampleSize(): void
    {
        $campaignId = $this->nextCampaignId();

        // Baseline window: only 2 sends - one less than the configured minimum of 3 - despite a
        // huge (100%) revenue drop, which would otherwise be real signal.
        $orderId = $this->createOrder(250.0);
        $this->insertOutcomeRow($campaignId, daysAgo: 10, orderId: $orderId);
        $orderId = $this->createOrder(250.0);
        $this->insertOutcomeRow($campaignId, daysAgo: 10, orderId: $orderId);

        // Current window: nothing at all.
        self::$objectManager->create(DetectAnomalies::class)->execute();

        self::assertNull(
            $this->findAlert($campaignId, AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP),
            'A campaign below the configured minimum sample size must never be flagged, however large the swing.'
        );
    }

    public function testCooldownPreventsASecondAlertForTheSameCampaignAndTypeWithinItsWindow(): void
    {
        $campaignId = $this->nextCampaignId();

        for ($i = 0; $i < self::MIN_SAMPLE_SIZE; $i++) {
            $orderId = $this->createOrder(100.0);
            $this->insertOutcomeRow($campaignId, daysAgo: 10, orderId: $orderId);
        }
        $orderId = $this->createOrder(30.0);
        $this->insertOutcomeRow($campaignId, daysAgo: 2, orderId: $orderId);

        $cron = self::$objectManager->create(DetectAnomalies::class);
        $cron->execute();
        self::assertNotNull(
            $this->findAlert($campaignId, AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP),
            'The first run must flag the real drop.'
        );

        // Same underlying data, a second run (e.g. the next day's cron tick) - the cooldown must
        // block a second alert for the same campaign+type within its window.
        $cron->execute();

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_anomaly_alert');
        $count = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['COUNT(*)'])
                ->where('campaign_id = ?', $campaignId)
                ->where('alert_type = ?', AnomalyAlert::TYPE_CAMPAIGN_REVENUE_DROP)
        );

        self::assertSame(
            1,
            $count,
            'A second run within the cooldown window must not insert a second alert for the same campaign+type.'
        );
    }

    // --- fixture builders ------------------------------------------------------------------

    private function nextCampaignId(): int
    {
        $campaignId = random_int(100_000, 999_999);
        $this->campaignIds[] = $campaignId;

        return $campaignId;
    }

    private function createOrder(float $baseGrandTotal): int
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $storeId = (int) self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $orderTable = $resourceConnection->getTableName('sales_order');
        // Guest order (customer_id left null) - sales_order.customer_id has a real FK to
        // customer_entity, but nothing in this test needs a real customer, only a real
        // base_grand_total for the revenue join.
        $connection->insert($orderTable, [
            'base_grand_total' => $baseGrandTotal,
            'grand_total' => $baseGrandTotal,
            'state' => 'complete',
            'status' => 'complete',
            'store_id' => $storeId,
            'created_at' => date('Y-m-d H:i:s'),
            'increment_id' => 'ORDOANOMALYTEST-' . uniqid('', true),
        ]);
        $orderId = (int) $connection->lastInsertId($orderTable);
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function insertOutcomeRow(
        int $campaignId,
        int $daysAgo,
        ?int $orderId = null,
        bool $converted = false
    ): void {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_campaign_outcome_log');

        $sentAt = date('Y-m-d H:i:s', time() - $daysAgo * 86400);

        $connection->insert($table, [
            'campaign_id' => $campaignId,
            'variant' => null,
            'customer_id' => random_int(1_000_000, 1_999_999),
            'sent_at' => $sentAt,
            'acted_at' => $converted || $orderId !== null ? $sentAt : null,
            'order_id' => $orderId,
            'trigger_order_id' => null,
            'is_holdout' => 0,
        ]);
    }

    /**
     * @return array{current_value: string, baseline_value: string, deviation_percent: string, message: string}|null
     */
    private function findAlert(int $campaignId, string $alertType): ?array
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_anomaly_alert');

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['current_value', 'baseline_value', 'deviation_percent', 'message'])
                ->where('campaign_id = ?', $campaignId)
                ->where('alert_type = ?', $alertType)
        );

        return $row === false ? null : $row;
    }
}
