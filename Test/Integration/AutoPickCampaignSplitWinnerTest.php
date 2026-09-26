<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\AutoPickCampaignSplitWinner;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use PHPUnit\Framework\TestCase;

/**
 * Closes SCENARIOS.md §28's remaining gap: Cron\AutoPickCampaignSplitWinner /
 * Model\Campaign\SplitWinnerCalculator were unit-tested only (calculator logic mocked DB access),
 * never proven against a real 'split' campaign action with real ordo_message_log/
 * ordo_message_log_event rows. Real DI/DB throughout - a real campaign + split action row, real
 * message-log rows (one variant with a higher click-through rate once both variants cross the
 * configured minimum sample size), then the real cron class is executed and the action's own
 * params are read back from the database to confirm the winning variant's weight actually
 * flipped to 100 and the loser's to 0.
 *
 * No transactional rollback (see magento-integration-test-lite) - tearDown() deletes the
 * campaign (cascades to its action) and the message-log rows this test created.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/AutoPickCampaignSplitWinnerTest.php
 */
class AutoPickCampaignSplitWinnerTest extends TestCase
{
    private const string CONFIG_PATH_ENABLED = 'ordo_scoring/ab_test/auto_winner_enabled';
    private const string CONFIG_PATH_MIN_SAMPLE_SIZE = 'ordo_scoring/ab_test/min_sample_size';

    private static ObjectManagerInterface $objectManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $messageLogIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);

        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)->save(
            self::CONFIG_PATH_ENABLED,
            1,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        self::$objectManager->get(ConfigWriter::class)->save(
            self::CONFIG_PATH_MIN_SAMPLE_SIZE,
            10,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    public static function tearDownAfterClass(): void
    {
        $storeId = self::$objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        self::$objectManager->get(ConfigWriter::class)->delete(
            self::CONFIG_PATH_ENABLED,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        self::$objectManager->get(ConfigWriter::class)->delete(
            self::CONFIG_PATH_MIN_SAMPLE_SIZE,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    protected function tearDown(): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $messageLogTable = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_message_log');

        foreach ($this->messageLogIds as $messageLogId) {
            $connection->delete($messageLogTable, ['entity_id = ?' => $messageLogId]);
        }
        $this->messageLogIds = [];

        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        foreach ($this->campaignIds as $campaignId) {
            $campaign = $campaignFactory->create();
            $campaignResource->load($campaign, $campaignId);
            if ($campaign->getEntityId()) {
                $campaignResource->delete($campaign);
            }
        }
        $this->campaignIds = [];
    }

    public function testDecidesWinnerOnceBothVariantsCrossMinimumSampleSize(): void
    {
        [$campaignId, $actionId] = $this->createCampaignWithSplitAction();

        // Below the configured min_sample_size (10) for both variants - not decided yet.
        $this->insertMessageLogs($campaignId, 'a', sent: 5, clicked: 5);
        $this->insertMessageLogs($campaignId, 'b', sent: 5, clicked: 0);

        self::$objectManager->create(AutoPickCampaignSplitWinner::class)->execute();

        $paramsAfterFirstPass = $this->loadActionParams($actionId);
        self::assertArrayNotHasKey(
            'winner',
            $paramsAfterFirstPass,
            'Neither variant has reached the minimum sample size yet - no decision should be made.'
        );

        // Cross the threshold: variant "a" now has a clearly higher CTR (10/10 vs 1/10).
        $this->insertMessageLogs($campaignId, 'a', sent: 5, clicked: 5);
        $this->insertMessageLogs($campaignId, 'b', sent: 5, clicked: 1);

        self::$objectManager->create(AutoPickCampaignSplitWinner::class)->execute();

        $paramsAfterSecondPass = $this->loadActionParams($actionId);
        self::assertSame('a', $paramsAfterSecondPass['winner'] ?? null);
        self::assertNotEmpty($paramsAfterSecondPass['winner_decided_at'] ?? null);

        $variantsByKey = [];
        foreach ($paramsAfterSecondPass['variants'] as $variant) {
            $variantsByKey[$variant['key']] = $variant;
        }
        self::assertSame(100, $variantsByKey['a']['weight']);
        self::assertSame(0, $variantsByKey['b']['weight']);
    }

    public function testDecisionIsPermanentAndNotOverwrittenByALaterPass(): void
    {
        [$campaignId, $actionId] = $this->createCampaignWithSplitAction();

        $this->insertMessageLogs($campaignId, 'a', sent: 10, clicked: 10);
        $this->insertMessageLogs($campaignId, 'b', sent: 10, clicked: 0);

        self::$objectManager->create(AutoPickCampaignSplitWinner::class)->execute();
        $decidedAt = $this->loadActionParams($actionId)['winner_decided_at'] ?? null;
        self::assertNotNull($decidedAt);

        // Even if variant "b" suddenly caught up, an already-decided split is never revisited.
        $this->insertMessageLogs($campaignId, 'b', sent: 20, clicked: 20);
        self::$objectManager->create(AutoPickCampaignSplitWinner::class)->execute();

        $paramsAfterSecondPass = $this->loadActionParams($actionId);
        self::assertSame('a', $paramsAfterSecondPass['winner']);
        self::assertSame($decidedAt, $paramsAfterSecondPass['winner_decided_at']);
    }

    /**
     * @return array{0: int, 1: int} [campaignId, actionId]
     */
    private function createCampaignWithSplitAction(): array
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test split campaign ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        /** @var CampaignAction $action */
        $action = $actionFactory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => 'split',
            'params' => json_encode([
                'variants' => [
                    ['key' => 'a', 'weight' => 50, 'actions' => []],
                    ['key' => 'b', 'weight' => 50, 'actions' => []],
                ],
            ]),
            'sort_order' => 0,
        ]);
        $actionResource->save($action);

        return [$campaignId, (int) $action->getEntityId()];
    }

    private function insertMessageLogs(int $campaignId, string $variant, int $sent, int $clicked): void
    {
        $connection = self::$objectManager->get(ResourceConnection::class)->getConnection();
        $messageLogTable = self::$objectManager->get(ResourceConnection::class)->getTableName('ordo_message_log');
        $messageLogEventTable = self::$objectManager->get(ResourceConnection::class)
            ->getTableName('ordo_message_log_event');

        for ($i = 0; $i < $sent; $i++) {
            $connection->insert($messageLogTable, [
                'channel' => 'email',
                'to_address' => sprintf('split-winner-test-%s@example.com', uniqid('', true)),
                'status' => 'sent',
                'campaign_id' => $campaignId,
                'variant' => $variant,
            ]);
            $messageLogId = (int) $connection->lastInsertId($messageLogTable);
            $this->messageLogIds[] = $messageLogId;

            if ($i < $clicked) {
                $connection->insert($messageLogEventTable, [
                    'message_log_id' => $messageLogId,
                    'event_type' => 'clicked',
                    'url' => 'https://example.com/',
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadActionParams(int $actionId): array
    {
        $actionFactory = self::$objectManager->get(CampaignActionFactory::class);
        $actionResource = self::$objectManager->get(CampaignActionResource::class);
        /** @var CampaignAction $action */
        $action = $actionFactory->create();
        $actionResource->load($action, $actionId);

        return $action->getParams();
    }
}
