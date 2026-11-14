<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Cron\CalculateProductPerformance;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB coverage for Cron\CalculateProductPerformance's watermark + per-SKU upsert-increment
 * logic - found via the 2026-11-14 full-sweep audit (Test/Mftf/SCENARIOS.md §42): only ever
 * unit-tested against a mocked connection, which can't prove the real
 * `INSERT ... ON DUPLICATE KEY UPDATE view_count = view_count + VALUES(view_count)` statement
 * actually increments rather than overwrites, or that the FlagManager watermark actually stops a
 * second run from recounting events it already processed.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/CalculateProductPerformanceTest.php
 */
class CalculateProductPerformanceTest extends TestCase
{
    private const FLAG_CODE = 'ordo_automation_product_performance_watermark';

    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $eventIds = [];

    /** @var string[] */
    private array $skus = [];

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
        self::$objectManager->get(FlagManager::class)->deleteFlag(self::FLAG_CODE);

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        if ($this->eventIds !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_visitor_event'),
                ['entity_id IN (?)' => $this->eventIds]
            );
        }
        $this->eventIds = [];

        if ($this->skus !== []) {
            $connection->delete(
                $resourceConnection->getTableName('ordo_product_performance'),
                ['sku IN (?)' => $this->skus]
            );
        }
        $this->skus = [];
    }

    private function logProductView(string $sku): int
    {
        $this->skus[] = $sku;

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_visitor_event');

        $connection->insert($table, [
            'visitor_id' => 'ordo-product-performance-test-' . uniqid('', true),
            'event_type' => 'product_view',
            'event_key' => $sku,
        ]);
        $eventId = (int) $connection->lastInsertId($table);
        $this->eventIds[] = $eventId;

        return $eventId;
    }

    private function logEvent(string $eventType, ?string $eventKey): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_visitor_event');

        $connection->insert($table, [
            'visitor_id' => 'ordo-product-performance-test-' . uniqid('', true),
            'event_type' => $eventType,
            'event_key' => $eventKey,
        ]);
        $this->eventIds[] = (int) $connection->lastInsertId($table);
    }

    private function viewCountFor(string $sku): ?int
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $value = $connection->fetchOne(
            $connection->select()
                ->from($resourceConnection->getTableName('ordo_product_performance'), ['view_count'])
                ->where('sku = ?', $sku)
        );

        return $value === false ? null : (int) $value;
    }

    public function testCountsProductViewEventsIntoViewCount(): void
    {
        $sku = 'ordo-ppt-' . uniqid('', true);
        $this->logProductView($sku);
        $this->logProductView($sku);
        $this->logProductView($sku);

        self::$objectManager->create(CalculateProductPerformance::class)->execute();

        self::assertSame(3, $this->viewCountFor($sku));
    }

    public function testIgnoresNonProductViewEventTypesAndNullEventKeys(): void
    {
        $sku = 'ordo-ppt-' . uniqid('', true);
        $this->logProductView($sku);
        $this->logEvent('page_view', $sku);
        $this->logEvent('product_view', null);

        self::$objectManager->create(CalculateProductPerformance::class)->execute();

        self::assertSame(
            1,
            $this->viewCountFor($sku),
            'A page_view event and a product_view event with no event_key must never be counted.'
        );
    }

    public function testIncrementsExistingViewCountOnASecondRunRatherThanOverwritingIt(): void
    {
        $sku = 'ordo-ppt-' . uniqid('', true);
        $this->logProductView($sku);
        $this->logProductView($sku);

        self::$objectManager->create(CalculateProductPerformance::class)->execute();
        self::assertSame(2, $this->viewCountFor($sku));

        $this->logProductView($sku);
        $this->logProductView($sku);
        $this->logProductView($sku);

        self::$objectManager->create(CalculateProductPerformance::class)->execute();

        self::assertSame(
            5,
            $this->viewCountFor($sku),
            'A second run must ADD its new events\' count to the existing view_count, not replace it.'
        );
    }

    public function testWatermarkPreventsRecountingTheSameEventsOnASecondRunWithNoNewEvents(): void
    {
        $sku = 'ordo-ppt-' . uniqid('', true);
        $this->logProductView($sku);
        $this->logProductView($sku);

        self::$objectManager->create(CalculateProductPerformance::class)->execute();
        self::assertSame(2, $this->viewCountFor($sku));

        // No new events logged - a second run must advance nothing, because the watermark
        // (a FlagManager-persisted entity_id, not a timestamp) already covers every row that
        // exists.
        self::$objectManager->create(CalculateProductPerformance::class)->execute();

        self::assertSame(
            2,
            $this->viewCountFor($sku),
            'A run with no new events past the watermark must never recount already-processed ones.'
        );
    }
}
