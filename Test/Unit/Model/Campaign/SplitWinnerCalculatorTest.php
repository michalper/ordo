<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\SplitWinnerCalculator;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as CampaignActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use PHPUnit\Framework\TestCase;

class SplitWinnerCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        return $select;
    }

    private function makeResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    private function makeConfig(bool $enabled, int $minSampleSize = 100): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isAbTestAutoWinnerEnabled')->willReturn($enabled);
        $config->method('getAbTestMinSampleSize')->willReturn($minSampleSize);

        return $config;
    }

    /**
     * @param CampaignAction[] $actionRows
     */
    private function makeCollectionFactory(array $actionRows): CampaignActionCollectionFactory
    {
        $collection = $this->createStub(CampaignActionCollection::class);
        $collection->method('addTypeFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($actionRows));

        $factory = $this->createStub(CampaignActionCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    private function makeActionRow(int $campaignId, array $params): CampaignAction
    {
        $action = $this->createStub(CampaignAction::class);
        $action->method('getCampaignId')->willReturn($campaignId);
        $action->method('getParams')->willReturn($params);

        return $action;
    }

    public function testDecideWinnersDoesNothingWhenDisabled(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $actionRow = $this->makeActionRow(1, ['variants' => [
            ['key' => 'a', 'weight' => 50],
            ['key' => 'b', 'weight' => 50],
        ]]);

        $calculator = new SplitWinnerCalculator(
            $this->makeConfig(enabled: false),
            $this->makeResourceConnection($connection),
            $this->makeCollectionFactory([$actionRow]),
            $this->createStub(CampaignActionResource::class)
        );

        self::assertSame(0, $calculator->decideWinners());
    }

    public function testDecideWinnersSkipsAnAlreadyDecidedAction(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $actionRow = $this->makeActionRow(1, [
            'variants' => [
                ['key' => 'a', 'weight' => 100],
                ['key' => 'b', 'weight' => 0],
            ],
            'winner' => 'a',
            'winner_decided_at' => '2026-01-01 00:00:00',
        ]);
        $resource = $this->createMock(CampaignActionResource::class);
        $resource->expects(self::never())->method('save');

        $calculator = new SplitWinnerCalculator(
            $this->makeConfig(enabled: true),
            $this->makeResourceConnection($connection),
            $this->makeCollectionFactory([$actionRow]),
            $resource
        );

        self::assertSame(0, $calculator->decideWinners());
    }

    public function testDecideWinnersSkipsWhenSampleSizeNotYetReached(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        // Both fetchPairs calls (sent, clicked) return under the minimum sample size.
        $connection->method('fetchPairs')->willReturn(['a' => 10, 'b' => 5]);

        $resource = $this->createMock(CampaignActionResource::class);
        $resource->expects(self::never())->method('save');

        $actionRow = $this->makeActionRow(1, ['variants' => [
            ['key' => 'a', 'weight' => 50, 'actions' => []],
            ['key' => 'b', 'weight' => 50, 'actions' => []],
        ]]);

        $calculator = new SplitWinnerCalculator(
            $this->makeConfig(enabled: true, minSampleSize: 100),
            $this->makeResourceConnection($connection),
            $this->makeCollectionFactory([$actionRow]),
            $resource
        );

        self::assertSame(0, $calculator->decideWinners());
    }

    public function testDecideWinnersPicksTheHigherCtrVariantAndSetsWinnerWeights(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        // Variant "a": 120 sent, 6 clicked -> 5% CTR. Variant "b": 150 sent, 30 clicked -> 20% CTR.
        $connection->method('fetchPairs')->willReturnOnConsecutiveCalls(
            ['a' => 120, 'b' => 150],
            ['a' => 6, 'b' => 30]
        );

        $savedParamsJson = null;
        $resource = $this->createMock(CampaignActionResource::class);
        $resource->expects(self::once())->method('save');

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getParams')->willReturn(['variants' => [
            ['key' => 'a', 'weight' => 50, 'actions' => [['type' => 'send_email']]],
            ['key' => 'b', 'weight' => 50, 'actions' => [['type' => 'send_email']]],
        ]]);
        $actionRow->expects(self::once())->method('setParamsJson')
            ->willReturnCallback(function (string $json) use (&$savedParamsJson, $actionRow) {
                $savedParamsJson = $json;
                return $actionRow;
            });

        $calculator = new SplitWinnerCalculator(
            $this->makeConfig(enabled: true, minSampleSize: 100),
            $this->makeResourceConnection($connection),
            $this->makeCollectionFactory([$actionRow]),
            $resource
        );

        self::assertSame(1, $calculator->decideWinners());

        self::assertNotNull($savedParamsJson);
        $saved = json_decode((string) $savedParamsJson, true);
        self::assertSame('b', $saved['winner']);
        self::assertArrayHasKey('winner_decided_at', $saved);

        $weightsByKey = [];
        foreach ($saved['variants'] as $variant) {
            $weightsByKey[$variant['key']] = $variant['weight'];
        }
        self::assertSame(100, $weightsByKey['b']);
        self::assertSame(0, $weightsByKey['a']);
        // The variant's own action chain is preserved, not dropped, by the weight rewrite.
        self::assertSame([['type' => 'send_email']], $saved['variants'][0]['actions']);
    }

    public function testDecideWinnersSkipsASingleVariantSplit(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resource = $this->createMock(CampaignActionResource::class);
        $resource->expects(self::never())->method('save');

        $actionRow = $this->makeActionRow(1, ['variants' => [
            ['key' => 'a', 'weight' => 100],
        ]]);

        $calculator = new SplitWinnerCalculator(
            $this->makeConfig(enabled: true),
            $this->makeResourceConnection($connection),
            $this->makeCollectionFactory([$actionRow]),
            $resource
        );

        self::assertSame(0, $calculator->decideWinners());
    }
}
